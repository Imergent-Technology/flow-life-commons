<?php

declare(strict_types=1);

use App\Modules\Relationships\Application\QualifyingRelationships;
use App\Modules\Relationships\Domain\DeletionCounts;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRecord;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Resources;

function relationshipPerson(string $name = 'Ada Lovelace'): string
{
    return Identity::savedPerson($name)->id->value;
}

function relationshipText(mixed $value): string
{
    assert(is_string($value));

    return $value;
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function relationshipCall(Console $console, string $method, string $path, array $body): TestResponse
{
    return match ($method) {
        'GET' => $console->get($path),
        'POST' => $console->post($path, $body),
        default => throw new RuntimeException($method),
    };
}

it('records one Guardian and one Volunteer for a Person, and refuses a second of the same type', function () {
    [$console] = Mfa::signedInAdmin();
    $person = relationshipPerson();

    $guardian = $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person,
        'confirm_existing_person' => true,
        'status' => 'active',
        'fields' => ['recognized_on' => '2020-01-15', 'stewardship' => 'Choir'],
    ])->assertCreated();
    $volunteer = $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => $person,
        'confirm_existing_person' => true,
        'status' => 'pending',
    ])->assertCreated();

    expect($guardian->json('relationship_id'))->not->toBe($volunteer->json('relationship_id'))
        ->and($guardian->json('revision'))->toBe(1)
        ->and($guardian->json('status'))->toBe('active')
        ->and($guardian->json('fields.stewardship'))->toBe('Choir')
        ->and(DB::table('person_relationships')->where('person_id', $person)->count())->toBe(2)
        ->and(DB::table('person_relationship_status_changes')->where('from_status', null)->count())->toBe(2);

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person,
        'confirm_existing_person' => true,
        'status' => 'inactive',
    ])->assertStatus(409)->assertJson(['code' => 'relationship_exists']);
    expect(DB::table('person_relationships')->where('person_id', $person)->where('relationship_type', 'guardian')->count())->toBe(1);
});

it('refuses intake that is unconfirmed, for an unknown Person, for a new Person, or with a default role, and writes nothing', function () {
    [$console] = Mfa::signedInAdmin();
    $before = DB::table('people')->count();

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => relationshipPerson(),
        'confirm_existing_person' => false,
        'status' => 'active',
    ])->assertStatus(422)->assertJson(['code' => 'confirmation_required']);

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => PersonId::generate()->value,
        'confirm_existing_person' => true,
        'status' => 'active',
    ])->assertStatus(422)->assertJson(['code' => 'unknown_person']);

    $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => relationshipPerson('New Person'),
        'confirm_existing_person' => true,
        'status' => 'pending',
        'new_person' => ['display_name' => 'Should Not Exist'],
    ])->assertStatus(422)->assertJson(['code' => 'new_person_not_supported']);

    $person = relationshipPerson('Role Person');
    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person,
        'confirm_existing_person' => true,
        'status' => 'active',
        'default_role' => 'grant',
    ])->assertStatus(422)->assertJson(['code' => 'default_role_not_applicable']);

    expect(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('people')->count())->toBe($before + 3);
});

it('follows each type\'s transitions, keeps history, and treats the same status as a no-op', function () {
    [$console] = Mfa::signedInAdmin();
    $person = relationshipPerson();
    $created = $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'pending',
    ])->assertCreated();
    $id = relationshipText($created->json('relationship_id'));

    $same = $console->put("/api/v1/admin/relationships/volunteers/{$person}/status", [
        'relationship_id' => $id, 'revision' => 1, 'status' => 'pending',
    ])->assertOk();
    expect($same->json('revision'))->toBe(1)
        ->and(DB::table('person_relationship_status_changes')->count())->toBe(1);

    $active = $console->put("/api/v1/admin/relationships/volunteers/{$person}/status", [
        'relationship_id' => $id, 'revision' => 1, 'status' => 'active',
    ])->assertOk();
    expect($active->json('revision'))->toBe(2)->and($active->json('status'))->toBe('active');

    $console->put("/api/v1/admin/relationships/volunteers/{$person}/status", [
        'relationship_id' => $id, 'revision' => 2, 'status' => 'pending',
    ])->assertOk()->assertJson(['status' => 'pending', 'revision' => 3]);

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
    ])->assertCreated();
    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => DB::table('person_relationships')->where('relationship_type', 'guardian')->value('id'),
        'revision' => 1,
        'status' => 'pending',
    ])->assertStatus(422)->assertJson(['code' => 'transition_not_allowed']);
});

it('rejects a stale revision before a bad field, and a stale instance after the relationship is recreated', function () {
    [$console] = Mfa::signedInAdmin();
    $person = relationshipPerson();
    $created = $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
        'fields' => ['stewardship' => 'Choir'],
    ])->assertCreated();
    $id = relationshipText($created->json('relationship_id'));

    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => 1, 'status' => 'inactive',
    ])->assertOk();

    $stale = $console->patch("/api/v1/admin/relationships/guardians/{$person}/fields", [
        'relationship_id' => $id, 'revision' => 1, 'fields' => ['stewardship' => ''],
    ])->assertStatus(409);
    expect($stale->json('code'))->toBe('stale_revision')
        ->and($stale->json('current.relationship_id'))->toBe($id)
        ->and($stale->json('current.revision'))->toBe(2)
        ->and(DB::table('person_relationships')->where('id', $id)->value('revision'))->toBe(2);

    $console->delete("/api/v1/admin/relationships/guardians/{$person}?relationship_id={$id}&revision=2")->assertNoContent();
    $recreated = $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
    ])->assertCreated();
    expect($recreated->json('revision'))->toBe(1)->and($recreated->json('relationship_id'))->not->toBe($id);

    $replay = $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => $id, 'revision' => 1, 'status' => 'inactive',
    ])->assertStatus(409);
    expect($replay->json('current.relationship_id'))->toBe($recreated->json('relationship_id'))
        ->and(DB::table('person_relationships')->where('id', $recreated->json('relationship_id'))->value('status'))->toBe('active');
});

it('validates metadata, clears with null, and does not move the revision when nothing changes', function () {
    [$console] = Mfa::signedInAdmin();
    $person = relationshipPerson();
    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
        'fields' => ['recognized_on' => '2020-01-15', 'stewardship' => 'Choir'],
    ])->assertCreated();

    $console->patch("/api/v1/admin/relationships/guardians/{$person}/fields", [
        'relationship_id' => DB::table('person_relationships')->value('id'),
        'revision' => 1,
        'fields' => ['recognized_on' => '2999-01-01'],
    ])->assertStatus(422)->assertJson(['code' => 'invalid_relationship_field']);
    expect(DB::table('person_relationships')->value('revision'))->toBe(1);

    $console->patch("/api/v1/admin/relationships/guardians/{$person}/fields", [
        'relationship_id' => DB::table('person_relationships')->value('id'),
        'revision' => 1,
        'fields' => ['stewardship' => ''],
    ])->assertStatus(422);

    $same = $console->patch("/api/v1/admin/relationships/guardians/{$person}/fields", [
        'relationship_id' => DB::table('person_relationships')->value('id'),
        'revision' => 1,
        'fields' => ['stewardship' => 'Choir'],
    ])->assertOk();
    expect($same->json('revision'))->toBe(1);

    $cleared = $console->patch("/api/v1/admin/relationships/guardians/{$person}/fields", [
        'relationship_id' => DB::table('person_relationships')->value('id'),
        'revision' => 1,
        'fields' => ['stewardship' => null],
    ])->assertOk();
    expect($cleared->json('revision'))->toBe(2)
        ->and($cleared->json('fields'))->not->toHaveKey('stewardship')
        ->and(DB::table('person_relationship_field_values')->where('field_key', 'stewardship')->count())->toBe(0)
        ->and(DB::table('person_relationship_field_values')->where('field_key', 'recognized_on')->value('value'))->toBe('2020-01-15');
});

it('deletes one relationship, keeps the Person and the other type, and records counts without field values', function () {
    [$console] = Mfa::signedInAdmin();
    $person = relationshipPerson('Kept Person');
    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
        'fields' => ['stewardship' => 'Secret choir note'],
    ])->assertCreated();
    $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
    ])->assertCreated();
    $guardian = DB::table('person_relationships')->where('relationship_type', 'guardian')->first();
    assert($guardian instanceof stdClass);
    $guardianId = relationshipText($guardian->id);
    $guardianRevision = $guardian->revision;
    assert(is_numeric($guardianRevision));

    $console->delete("/api/v1/admin/relationships/guardians/{$person}?relationship_id={$guardianId}&revision={$guardianRevision}")
        ->assertNoContent();

    $event = DB::table('security_events')->where('type', 'relationship.deleted')->first();
    assert($event instanceof stdClass);
    $context = relationshipText($event->context);
    expect(DB::table('people')->where('id', $person)->exists())->toBeTrue()
        ->and(DB::table('person_relationships')->where('relationship_type', 'volunteer')->exists())->toBeTrue()
        ->and(DB::table('person_relationships')->where('relationship_type', 'guardian')->exists())->toBeFalse()
        ->and(DB::table('person_relationship_field_values')->count())->toBe(0)
        ->and(DB::table('person_relationship_status_changes')->where('relationship_id', $guardianId)->count())->toBe(0)
        ->and($context)->not->toContain('Secret choir note')
        ->and($context)->toContain($guardianId);
});

it('answers a missing relationship as not found, whether or not the Person exists', function () {
    [$console] = Mfa::signedInAdmin();
    $missing = PersonId::generate()->value;
    $present = relationshipPerson();

    $console->get("/api/v1/admin/relationships/guardians/{$missing}")->assertStatus(404)->assertJson(['code' => 'relationship_not_found']);
    $console->get("/api/v1/admin/relationships/guardians/{$present}")->assertStatus(404)->assertJson(['code' => 'relationship_not_found']);
    $console->get("/api/v1/admin/relationships/guardians/{$present}/management")->assertNotFound();
});

it('lists names and status only, and looks up candidates by display name', function () {
    [$console] = Mfa::signedInAdmin();
    $ada = relationshipPerson('Ada Lovelace');
    $grace = relationshipPerson('Grace Hopper');
    $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => $ada, 'confirm_existing_person' => true, 'status' => 'active',
    ])->assertCreated();
    $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => $grace, 'confirm_existing_person' => true, 'status' => 'pending',
    ])->assertCreated();

    $page = $console->get('/api/v1/admin/relationships/volunteers?q=ada&status=active')->assertOk();
    expect($page->json('data'))->toHaveCount(1)
        ->and($page->json('data.0.person.display_name'))->toBe('Ada Lovelace')
        ->and($page->json('data.0'))->not->toHaveKey('email')
        ->and(json_encode($page->json()))->not->toContain('phone');

    $console->get('/api/v1/admin/relationships/volunteers?status=former')->assertStatus(422)->assertJson(['code' => 'unknown_relationship_status']);
    $console->get('/api/v1/admin/relationships/volunteers?per_page=0')->assertStatus(422)->assertJson(['code' => 'invalid_relationship_query']);

    $candidates = $console->get('/api/v1/admin/relationships/volunteers/candidates?q=ada')->assertOk();
    expect($candidates->json('data.0.matched_on'))->toBe('display_name')
        ->and($candidates->json('data.0.status'))->toBe('active')
        ->and(json_encode($candidates->json()))->not->toContain('@');
    $console->get('/api/v1/admin/relationships/volunteers/candidates?q=a')->assertStatus(422);
});

it('describes types the actor may see and never offers a default role', function () {
    [$console] = Mfa::signedInAdmin();
    $types = $console->get('/api/v1/admin/relationship-types')->assertOk()->json('data');
    assert(is_array($types));
    expect(array_column($types, 'type'))->toBe(['guardian', 'volunteer']);
    foreach ($types as $type) {
        assert(is_array($type));
        $actions = $type['actions'] ?? null;
        assert(is_array($actions));
        expect($type['default_role'])->toBeNull()
            ->and($actions['provision_default_role'])->toBeFalse();
    }
});

it('qualifies only a status the catalog marks, in definition order', function () {
    [$console] = Mfa::signedInAdmin();
    $person = relationshipPerson();
    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'inactive',
    ])->assertCreated();
    $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
    ])->assertCreated();

    $qualifying = array_map(fn (RelationshipType $type): string => $type->key, app(QualifyingRelationships::class)(PersonId::fromString($person)));
    expect($qualifying)->toBe(['volunteer']);

    $console->put("/api/v1/admin/relationships/guardians/{$person}/status", [
        'relationship_id' => DB::table('person_relationships')->where('relationship_type', 'guardian')->value('id'),
        'revision' => 1,
        'status' => 'active',
    ])->assertOk();
    $both = array_map(fn (RelationshipType $type): string => $type->key, app(QualifyingRelationships::class)(PersonId::fromString($person)));
    expect($both)->toBe(['guardian', 'volunteer']);
});

it('lets today\'s guardian view Guardians and manage Volunteers, and refuses them Guardian management', function () {
    [$console] = Resources::signedInGuardian();
    $person = relationshipPerson();

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
    ])->assertForbidden();
    expect(DB::table('person_relationships')->count())->toBe(0);

    $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'pending',
    ])->assertCreated();
    $console->get("/api/v1/admin/relationships/volunteers/{$person}")->assertOk();
    $console->get('/api/v1/admin/relationships/guardians')->assertOk();
});

it('requires recent verification for Guardian recognition and status, and for every deletion, and not for a field edit', function () {
    [$console] = Mfa::signedInAdmin();
    $person = relationshipPerson();
    $console->tamperSession(fn ($session) => $session->forget('security_verified_at'));

    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
    ])->assertForbidden();
    $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'pending',
        'fields' => ['interests' => 'Singing'],
    ])->assertCreated();
    $id = relationshipText(DB::table('person_relationships')->value('id'));
    $console->put("/api/v1/admin/relationships/volunteers/{$person}/status", [
        'relationship_id' => $id, 'revision' => 1, 'status' => 'active',
    ])->assertOk();
    $console->patch("/api/v1/admin/relationships/volunteers/{$person}/fields", [
        'relationship_id' => $id, 'revision' => 2, 'fields' => ['availability' => 'Evenings'],
    ])->assertOk();
    $console->delete("/api/v1/admin/relationships/volunteers/{$person}?relationship_id={$id}&revision=3")->assertForbidden();
    expect(DB::table('person_relationships')->where('id', $id)->exists())->toBeTrue();
});

it('isolates each route to its own capability', function () {
    [$console] = Mfa::signedInAdmin();
    $person = relationshipPerson('Isolated');
    $console->post('/api/v1/admin/relationships/guardians', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'active',
    ])->assertCreated();
    $id = DB::table('person_relationships')->value('id');

    $allowed = 'crm.people.view';
    Gate::before(function ($user, string $ability) use (&$allowed): bool {
        return $ability === 'console.access' || $ability === $allowed;
    });
    $operations = [
        ['GET', '/api/v1/admin/relationships/guardians', [], 'guardians.view'],
        ['GET', "/api/v1/admin/relationships/guardians/{$person}", [], 'guardians.view'],
        ['POST', '/api/v1/admin/relationships/volunteers', [
            'person_id' => relationshipPerson('Other'), 'confirm_existing_person' => true, 'status' => 'pending',
        ], 'volunteers.manage'],
    ];
    foreach ($operations as [$method, $path, $body, $capability]) {
        $allowed = 'crm.people.view';
        $refused = relationshipCall($console, $method, $path, $body);
        expect($refused->status())->toBe(403, "{$method} {$path} with a CRM capability");

        $allowed = $capability;
        $allowedResponse = relationshipCall($console, $method, $path, $body);
        expect($allowedResponse->status())->not->toBe(403, "{$method} {$path} with {$capability}");
    }

    expect($id)->not->toBeNull();
});

it('refuses a directory whose person set is larger than a search can compose', function () {
    [$console] = Mfa::signedInAdmin();
    $inner = app(RelationshipRepository::class);
    app()->instance(RelationshipRepository::class, new class($inner) implements RelationshipRepository
    {
        public function __construct(private RelationshipRepository $inner) {}

        public function personIds(string $typeKey, ?string $status): array
        {
            return array_map(fn (): string => PersonId::generate()->value, range(1, 10_001));
        }

        public function find(PersonId $person, string $typeKey): ?RelationshipRecord
        {
            return $this->inner->find($person, $typeKey);
        }

        public function lock(PersonId $person, string $typeKey): ?RelationshipRecord
        {
            return $this->inner->lock($person, $typeKey);
        }

        public function add(RelationshipRecord $record): void
        {
            $this->inner->add($record);
        }

        public function saveStatus(RelationshipRecord $updated, int $expectedRevision): bool
        {
            return $this->inner->saveStatus($updated, $expectedRevision);
        }

        public function saveFields(RelationshipRecord $updated, int $expectedRevision, array $written, array $cleared, PersonId $by, DateTimeImmutable $at): bool
        {
            return $this->inner->saveFields($updated, $expectedRevision, $written, $cleared, $by, $at);
        }

        public function delete(RelationshipId $id): DeletionCounts
        {
            return $this->inner->delete($id);
        }

        public function statusesFor(string $typeKey, array $personIds): array
        {
            return [];
        }

        public function currentOf(PersonId $person): array
        {
            return [];
        }

        public function storedRelationships(): array
        {
            return [];
        }

        public function storedFieldValues(): array
        {
            return [];
        }
    });

    $console->get('/api/v1/admin/relationships/guardians')->assertStatus(422)->assertJson(['code' => 'search_too_broad']);
});

it('reports an unknown stored status without echoing a field value', function () {
    [$console] = Mfa::signedInAdmin();
    $person = relationshipPerson();
    $console->post('/api/v1/admin/relationships/volunteers', [
        'person_id' => $person, 'confirm_existing_person' => true, 'status' => 'pending',
        'fields' => ['interests' => 'do-not-print-this-value'],
    ])->assertCreated();
    DB::table('person_relationships')->update(['status' => 'retired']);

    Artisan::call('relationships:check');
    expect(Artisan::output())->toContain('unknown status retired')
        ->and(Artisan::output())->not->toContain('do-not-print-this-value');
    expect(Artisan::call('relationships:check'))->toBe(1);
});
