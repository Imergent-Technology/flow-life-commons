<?php

declare(strict_types=1);

use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\Role;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;

/*
 * Assigning roles through the administration surface (ADR 0024): GrantRole and RevokeRole, unchanged, with the catalog
 * served by Access.
 */

/**
 * @param  list<array<string, mixed>>  $catalog
 * @return list<string>
 */
function capabilitiesOf(array $catalog, string $key): array
{
    foreach ($catalog as $role) {
        if ($role['key'] === $key) {
            return Api::strings($role['capabilities']);
        }
    }

    return [];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-22 12:00:00');
});

it('serves the role catalog from Access, so the Console defines none', function () {
    [$console] = Mfa::signedInAdmin();

    $catalog = Api::rows($console->get('/api/v1/admin/roles')->assertOk()->json('data'));

    expect(array_column($catalog, 'key'))->toBe(array_map(fn (Role $r): string => $r->value, Role::cases()))
        ->and(array_keys($catalog[0]))->toBe(['key', 'name', 'description', 'capabilities'])
        ->and(capabilitiesOf($catalog, Role::Guardian->value))->toBe(['console.access'])
        // The administrator's capabilities are the whole catalog: derived, not listed.
        ->and(capabilitiesOf($catalog, Role::PlatformAdministrator->value))->toBe(Access::everyCapabilityId());
});

it('grants a catalog role through GrantRole: audited with the actor, and it takes effect on the very next decision', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target@example.org');

    $response = $console->post("/api/v1/admin/accounts/{$target->id->value}/assignments", ['key' => 'guardian'])->assertOk();

    $events = Identity::events('role.granted');
    expect($response->json('assignments.0.key'))->toBe('guardian')
        ->and($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and($events[0]->subject_person_id)->toBe($target->personId->value)
        ->and(DB::table('role_assignments')->where('person_id', $target->personId->value)->value('granted_by_account_id'))->toBe($admin->id->value);
});

it('is idempotent: granting a role already held changes and records nothing', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target@example.org');
    $console->post("/api/v1/admin/accounts/{$target->id->value}/assignments", ['key' => 'guardian'])->assertOk();

    $console->post("/api/v1/admin/accounts/{$target->id->value}/assignments", ['key' => 'guardian'])->assertOk()->assertJsonCount(1, 'assignments');

    expect(Identity::events('role.granted'))->toHaveCount(1)->and(DB::table('role_assignments')->where('person_id', $target->personId->value)->count())->toBe(1);
});

it('revokes through RevokeRole, audited; revoking a role not held is a quiet no-op', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');

    $console->delete("/api/v1/admin/accounts/{$target->id->value}/assignments/guardian")->assertOk()->assertJsonCount(0, 'assignments');
    $console->delete("/api/v1/admin/accounts/{$target->id->value}/assignments/guardian")->assertOk();

    $events = Identity::events('role.revoked');
    expect($events)->toHaveCount(1)->and($events[0]->actor_account_id)->toBe($admin->id->value);
});

it('refuses an unknown role key, and a CAPABILITY named as one: the client can never grant a capability', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('target@example.org');

    foreach (['superuser', 'console.access', 'PLATFORM_ADMINISTRATOR', ''] as $key) {
        $console->post("/api/v1/admin/accounts/{$target->id->value}/assignments", ['key' => $key])->assertStatus(422);
    }
    $console->post("/api/v1/admin/accounts/{$target->id->value}/assignments", ['key' => 'superuser'])->assertJson(['code' => 'unknown_role']);
    // An extra field is ignored, never honoured.
    $console->post("/api/v1/admin/accounts/{$target->id->value}/assignments", ['key' => 'guardian', 'capabilities' => ['access.roles.assign']])->assertOk();
    $console->delete("/api/v1/admin/accounts/{$target->id->value}/assignments/superuser")->assertStatus(422)->assertJson(['code' => 'unknown_role']);

    expect(DB::table('role_assignments')->where('person_id', $target->personId->value)->pluck('role_key')->all())->toBe(['guardian']);
});

it('keeps the last-administrator protection through HTTP', function () {
    [$console, $admin] = Mfa::signedInAdmin();

    $console->delete("/api/v1/admin/accounts/{$admin->id->value}/assignments/platform_administrator")
        ->assertStatus(409)->assertJson(['code' => 'last_administrator_required']);

    expect(Access::activeAdministrators())->toBe(1)->and(Identity::events('role.revoked'))->toBe([]);
});

it('lets an administrator revoke another administrator\'s role while one remains', function () {
    [$console] = Mfa::signedInAdmin();
    $second = Access::admin('second@example.org');

    $console->delete("/api/v1/admin/accounts/{$second->id->value}/assignments/platform_administrator")->assertOk();

    expect(Access::activeAdministrators())->toBe(1);
});

it('applies a granted role to the person immediately: a Console user\'s capabilities are read fresh', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Mfa::guardian('target@example.org');
    $factor = Mfa::enroll($target, 'MFRGGZDFMZTWQ2LKNNWG23TPOBYXE43U');
    $person = new Console;
    $person->loginWithMfa('target@example.org', Identity::PASSWORD, $factor['secret'])->assertOk();
    expect($person->me()->json('capabilities'))->toBe(['console.access']);

    $console->post("/api/v1/admin/accounts/{$target->id->value}/assignments", ['key' => 'platform_administrator'])->assertOk();

    // No new sign-in: the next request sees every capability.
    expect($person->me()->json('capabilities'))->toBe(Access::everyCapabilityId());
    $console->delete("/api/v1/admin/accounts/{$target->id->value}/assignments/platform_administrator")->assertOk();
    expect($person->me()->json('capabilities'))->toBe(['console.access']);
    expect(Capability::cases())->not->toBeEmpty();
});
