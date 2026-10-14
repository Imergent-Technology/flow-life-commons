<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Resources;

/*
 * ADR 0038's verification matrix, rows the G10 closeout (WP7) found proved only in part: every type's every operation is authorized by
 * exactly the capability its definition names and by no other relationship capability (Capabilities: Guardian view-only and
 * manage-only, Volunteer view-only and manage-only, definition-selected capability), an unknown field and another type's field are
 * refused with `unknown_relationship_field` and change nothing (Metadata: unknown; no leakage across types), and the provisioning hint
 * is true only for the Guardian manage capability together with `access.roles.assign` (Provisioning hint never authorizes). Console
 * admission alone reaches no operation (Self-service cannot provision). The provisioning hint is proved for the cells a real persona
 * reaches. "Manages Guardians but cannot assign roles" is reached by no real role (A3). Its server refusal is isolated in
 * `GuardianProvisioningTest` (`denyRoleAssignment()`: the Actor manages Guardians and Access finds no role-assignment authority, so
 * `role_provisioning_not_permitted`, the intake rollback and `defer`), and the hint's `access.roles.assign` conjunct is isolated in
 * `ProvisioningHintTest`, through a test-only definition that borrows a pair the Guardian role does hold. Runs on MariaDB and PostgreSQL.
 */

const MATRIX_CAPABILITIES = ['guardians.view', 'guardians.manage', 'volunteers.view', 'volunteers.manage'];

/**
 * Lets the signed-in administrator hold the Console and exactly the capabilities in `$held` (a reference, so a test can move it).
 *
 * @param  list<string>  $held
 */
function holdOnly(array &$held): void
{
    Gate::before(function ($user, string $ability) use (&$held): bool {
        return $ability === 'console.access' || in_array($ability, $held, true);
    });
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function matrixCall(Console $console, string $method, string $path, array $body = []): TestResponse
{
    return match ($method) {
        'GET' => $console->get($path),
        'POST' => $console->post($path, $body),
        'PUT' => $console->put($path, $body),
        'PATCH' => $console->patch($path, $body),
        'DELETE' => $console->delete($path),
        default => throw new RuntimeException($method),
    };
}

/**
 * The relationship's current instance and revision, straight from the table.
 *
 * @return array{string, int}
 */
function matrixCurrent(string $person, string $type): array
{
    $row = DB::table('person_relationships')->where('person_id', $person)->where('relationship_type', $type)->first();
    assert($row instanceof stdClass);

    return [Resources::str($row->id), Resources::int($row->revision)];
}

/**
 * Every operation of a type, with the capability its definition names for it. Each is built when it is run, so it names the
 * relationship's current instance and revision.
 *
 * @return list<array{string, string, Closure(): array<string, mixed>, string, string}> [label, method, body, path, capability]
 */
function matrixOperations(string $slug, string $type, string $person, string $view, string $manage): array
{
    $current = fn (): array => matrixCurrent($person, $type);
    $root = "/api/v1/admin/relationships/{$slug}";
    $operations = [
        ['directory', 'GET', fn (): array => [], $root, $view],
        ['record', 'GET', fn (): array => [], "{$root}/{$person}", $view],
        ['management view', 'GET', fn (): array => [], "{$root}/{$person}/management", $manage],
        ['candidates', 'GET', fn (): array => [], "{$root}/candidates?q=Matrix", $manage],
        ['intake', 'POST', fn (): array => ['person_id' => Identity::savedPerson('Matrix Intake')->id->value, 'confirm_existing_person' => true, 'status' => 'active', 'fields' => []] + ($type === 'guardian' ? ['default_role' => 'defer'] : []), $root, $manage],
        ['status', 'PUT', fn (): array => ['relationship_id' => $current()[0], 'revision' => $current()[1], 'status' => 'active'], "{$root}/{$person}/status", $manage],
        ['fields', 'PATCH', fn (): array => ['relationship_id' => $current()[0], 'revision' => $current()[1], 'fields' => []], "{$root}/{$person}/fields", $manage],
    ];
    if ($type === 'guardian') {
        $operations[] = ['default role', 'PUT', fn (): array => ['relationship_id' => $current()[0], 'revision' => $current()[1], 'default_role' => 'decline'], "{$root}/{$person}/default-role", $manage];
    } else {
        $operations[] = ['basic details', 'PATCH', fn (): array => ['relationship_id' => $current()[0], 'display_name' => ['from' => 'Matrix Person', 'to' => 'Matrix Person']], "{$root}/{$person}/basics", $manage];
    }

    return $operations;
}

dataset('relationship types', [
    'Guardians' => ['guardians', 'guardian', 'guardians.view', 'guardians.manage'],
    'Volunteers' => ['volunteers', 'volunteer', 'volunteers.view', 'volunteers.manage'],
]);

it('authorizes every operation of a type by exactly the capability its definition names, and by no other relationship capability', function (string $slug, string $type, string $view, string $manage) {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Matrix Person')->id->value;
    $console->post("/api/v1/admin/relationships/{$slug}", ['person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active', 'fields' => []]
        + ($type === 'guardian' ? ['default_role' => 'defer'] : []))->assertCreated();
    $held = [];
    holdOnly($held);

    foreach (matrixOperations($slug, $type, $person, $view, $manage) as [$label, $method, $body, $path, $capability]) {
        // Console admission alone (a self-service Console user) reaches nothing, intake and the default-role decision included.
        $held = [];
        expect(matrixCall($console, $method, $path, $body())->status())->toBe(403, "{$slug} {$label} with Console admission alone");
        foreach (MATRIX_CAPABILITIES as $other) {
            if ($other === $capability) {
                continue;
            }
            $held = [$other];
            $before = DB::table('person_relationships')->count();
            expect(matrixCall($console, $method, $path, $body())->status())->toBe(403, "{$slug} {$label} with only {$other}")
                ->and(DB::table('person_relationships')->count())->toBe($before);
        }
        $held = [$capability];
        // The positive cell is the exact success, not merely "not forbidden": a 500, a 404 or a 422 here would pass the gate and
        // prove nothing about authorization. Only intake creates, so only it answers 201.
        expect(matrixCall($console, $method, $path, $body())->status())->toBe($label === 'intake' ? 201 : 200, "{$slug} {$label} with {$capability}");
    }

    // Deletion last, as it ends the relationship: the type's own manage capability, and nothing else.
    $delete = fn (): string => "/api/v1/admin/relationships/{$slug}/{$person}?relationship_id=".matrixCurrent($person, $type)[0].'&revision='.matrixCurrent($person, $type)[1];
    foreach (array_diff(MATRIX_CAPABILITIES, [$manage]) as $other) {
        $held = [$other];
        expect($console->delete($delete())->status())->toBe(403, "{$slug} deletion with only {$other}");
    }
    $held = [$manage];
    expect($console->delete($delete())->status())->toBe(204);
})->with('relationship types');

it('refuses an unknown field, and another type\'s field, with unknown_relationship_field, writing nothing', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Field Person')->id->value;
    $console->post('/api/v1/admin/relationships/guardians', ['person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active', 'fields' => [], 'default_role' => 'defer'])->assertCreated();
    $console->post('/api/v1/admin/relationships/volunteers', ['person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active', 'fields' => []])->assertCreated();
    $values = fn (): int => DB::table('person_relationship_field_values')->count();
    $before = $values();

    foreach ([
        ['guardians', 'guardian', ['favourite_colour' => 'green']],   // a key no type defines
        ['guardians', 'guardian', ['interests' => 'Gardening']],      // a Volunteer field on the Guardian route
        ['volunteers', 'volunteer', ['stewardship' => 'The garden']], // a Guardian field on the Volunteer route
        ['volunteers', 'volunteer', ['recognized_on' => '2020-01-01']],
    ] as [$slug, $type, $fields]) {
        [$id, $revision] = matrixCurrent($person, $type);
        $response = $console->patch("/api/v1/admin/relationships/{$slug}/{$person}/fields", ['relationship_id' => $id, 'revision' => $revision, 'fields' => $fields]);
        expect($response->status())->toBe(422, (string) json_encode($fields))
            ->and($response->json('code'))->toBe('unknown_relationship_field')
            ->and(matrixCurrent($person, $type)[1])->toBe($revision);
    }
    // And at intake.
    $intake = $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => Identity::savedPerson('Field Intake')->id->value, 'confirm_existing_person' => true, 'status' => 'active', 'fields' => ['stewardship' => 'The garden'],
    ]);
    expect($intake->status())->toBe(422)
        ->and($intake->json('code'))->toBe('unknown_relationship_field')
        ->and($values())->toBe($before)
        ->and(DB::table('person_relationships')->count())->toBe(2);

    // The positive control: the type's own field is accepted.
    [$id, $revision] = matrixCurrent($person, 'volunteer');
    $console->patch("/api/v1/admin/relationships/volunteers/{$person}/fields", ['relationship_id' => $id, 'revision' => $revision, 'fields' => ['interests' => 'Gardening']])->assertOk();
    expect($values())->toBe($before + 1);
});

it('offers the provisioning hint only to a manager who can also assign roles, and never to one who cannot', function () {
    /** @return array<string, array{view: bool, manage: bool, hint: bool}> the served types' actions, by type key */
    $served = function (Console $console): array {
        $types = $console->get('/api/v1/admin/relationship-types')->assertOk()->json('data');
        assert(is_array($types));
        $out = [];
        foreach ($types as $type) {
            assert(is_array($type) && is_array($type['actions']));
            $out[Resources::str($type['type'])] = [
                'view' => (bool) $type['actions']['view'],
                'manage' => (bool) $type['actions']['manage'],
                'hint' => (bool) $type['actions']['provision_default_role'],
            ];
        }

        return $out;
    };

    // The administrator manages Guardians and assigns roles: the hint, for the one type that has a default role.
    [$admin] = Mfa::signedInAdmin();
    expect($served($admin))->toBe([
        'guardian' => ['view' => true, 'manage' => true, 'hint' => true],
        'volunteer' => ['view' => true, 'manage' => true, 'hint' => false],
    ]);

    // The Guardian role's holder views Guardians, manages Volunteers and assigns no role: no hint anywhere, whatever they manage.
    [$guardian] = Resources::signedInGuardian('hint.guardian@example.org', 'Hint Guardian');
    expect($served($guardian))->toBe([
        'guardian' => ['view' => true, 'manage' => false, 'hint' => false],
        'volunteer' => ['view' => true, 'manage' => true, 'hint' => false],
    ]);

    // And the hint is no authority: the same Guardian-role holder who is told `false` cannot grant by sending the choice anyway.
    $person = Identity::savedPerson('Hint Person')->id->value;
    $refused = $guardian->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active', 'default_role' => 'grant',
    ]);
    expect($refused->status())->toBe(403)
        ->and(DB::table('person_relationships')->where('person_id', $person)->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});
