<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Crm\Application\ProfileChanges;
use App\Modules\Crm\Application\UpdateContactMethod;
use App\Modules\Crm\Application\UpdatePerson;
use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Identity\Domain\Person;
use App\Modules\Relationships\Application\ChangeRelationshipStatus;
use App\Modules\Relationships\Application\DecideDefaultRole;
use App\Modules\Relationships\Application\DefaultRoleChoice;
use App\Modules\Relationships\Application\DeleteRelationship;
use App\Modules\Relationships\Application\EstablishRelationship;
use App\Modules\Relationships\Application\NewPersonDetails;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Application\RelationshipExists;
use App\Modules\Relationships\Application\RelationshipNotFound;
use App\Modules\Relationships\Application\StaleBasicDetails;
use App\Modules\Relationships\Application\StaleRelationshipRevision;
use App\Modules\Relationships\Application\UpdateRelationshipPersonBasics;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\Actor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Crm;
use Tests\Support\Identity;
use Tests\Support\Race;
use Tests\Support\RelationshipsPauses;

/*
 * Relationship writes under two processes (ADR 0038, T1). The pause is after the read or the insert and before
 * commit, so a missing lock or a missing unique index lets the second process finish instead of waiting.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
});

afterEach(function () {
    Race::clean();
});

function relationshipActor(): Actor
{
    $account = Identity::savedActiveAccount('race-admin-'.bin2hex(random_bytes(3)).'@example.org', name: 'Race Admin');
    Access::grant($account, Role::PlatformAdministrator);

    return Access::actorFor($account);
}

it('lets exactly one of two intakes of the same Person and type commit', function () {
    $actor = relationshipActor();
    $person = Identity::savedPerson('Same Person');
    $type = app(RelationshipCatalog::class)->type('guardian');
    assert($type !== null);

    $race = Race::against(function (Closure $pause) use ($actor, $person, $type): void {
        RelationshipsPauses::after('add', $pause);
        app(EstablishRelationship::class)($actor, $type, $person->id, true, 'active', [], true, DefaultRoleChoice::Defer);
    }, null, 'relationship_intake', [
        'actor_account' => $actor->accountId->value,
        'actor_person' => $actor->personId->value,
        'person' => $person->id->value,
        'type' => 'guardian',
        'status' => 'inactive',
    ]);

    expect($race['blocked'])->toBeTrue()
        ->and($race['class'])->toBe(RelationshipExists::class)
        ->and(DB::table('person_relationships')->where('person_id', $person->id->value)->count())->toBe(1);
});

it('lets two intakes of different types for one Person both commit', function () {
    $actor = relationshipActor();
    $person = Identity::savedPerson('Both Types');
    $guardian = app(RelationshipCatalog::class)->type('guardian');
    assert($guardian !== null);

    $race = Race::against(function (Closure $pause) use ($actor, $person, $guardian): void {
        RelationshipsPauses::after('add', $pause);
        app(EstablishRelationship::class)($actor, $guardian, $person->id, true, 'active', [], true, DefaultRoleChoice::Defer);
    }, null, 'relationship_intake', [
        'actor_account' => $actor->accountId->value,
        'actor_person' => $actor->personId->value,
        'person' => $person->id->value,
        'type' => 'volunteer',
        'status' => 'pending',
    ]);

    expect($race['blocked'])->toBeFalse()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('person_relationships')->where('person_id', $person->id->value)->count())->toBe(2);
});

it('serializes two status changes of one revision, and a field edit against a status change', function () {
    $actor = relationshipActor();
    $person = Identity::savedPerson('Contended');
    $type = app(RelationshipCatalog::class)->type('volunteer');
    assert($type !== null);
    $created = app(EstablishRelationship::class)($actor, $type, $person->id, true, 'pending', [], false);

    $status = Race::against(function (Closure $pause) use ($actor, $person, $type, $created): void {
        RelationshipsPauses::after('lock', $pause);
        app(ChangeRelationshipStatus::class)($actor, $type, $person->id, $created->id, 1, 'active', false);
    }, null, 'relationship_status', [
        'actor_account' => $actor->accountId->value,
        'actor_person' => $actor->personId->value,
        'person' => $person->id->value,
        'type' => 'volunteer',
        'relationship' => $created->id->value,
        'revision' => '1',
        'status' => 'inactive',
    ]);

    expect($status['blocked'])->toBeTrue()
        ->and($status['class'])->toBe(StaleRelationshipRevision::class)
        ->and(DB::table('person_relationships')->where('id', $created->id->value)->value('status'))->toBe('active')
        ->and(DB::table('person_relationship_status_changes')->where('relationship_id', $created->id->value)->count())->toBe(2);

    $fields = Race::against(function (Closure $pause) use ($actor, $person, $type, $created): void {
        RelationshipsPauses::after('lock', $pause);
        app(ChangeRelationshipStatus::class)($actor, $type, $person->id, $created->id, 2, 'inactive', false);
    }, null, 'relationship_fields', [
        'actor_account' => $actor->accountId->value,
        'actor_person' => $actor->personId->value,
        'person' => $person->id->value,
        'type' => 'volunteer',
        'relationship' => $created->id->value,
        'revision' => '2',
        'value' => 'Singing',
    ]);

    expect($fields['blocked'])->toBeTrue()
        ->and(DB::table('person_relationships')->where('id', $created->id->value)->value('revision'))->toBe(3)
        ->and(DB::table('person_relationship_field_values')->count())->toBe(0);
});

it('does not let a status change land on a relationship a deletion removed', function () {
    $actor = relationshipActor();
    $person = Identity::savedPerson('Deleted');
    $type = app(RelationshipCatalog::class)->type('guardian');
    assert($type !== null);
    $created = app(EstablishRelationship::class)($actor, $type, $person->id, true, 'active', ['stewardship' => 'Choir'], true, DefaultRoleChoice::Defer);

    $race = Race::against(function (Closure $pause) use ($actor, $person, $type, $created): void {
        RelationshipsPauses::after('lock', $pause);
        app(DeleteRelationship::class)($actor, $type, $person->id, $created->id, 1);
    }, null, 'relationship_status', [
        'actor_account' => $actor->accountId->value,
        'actor_person' => $actor->personId->value,
        'person' => $person->id->value,
        'type' => 'guardian',
        'relationship' => $created->id->value,
        'revision' => '1',
        'status' => 'inactive',
    ]);

    expect($race['blocked'])->toBeTrue()
        ->and(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('person_relationship_field_values')->count())->toBe(0)
        ->and(DB::table('person_relationship_status_changes')->count())->toBe(0)
        ->and(DB::table('people')->where('id', $person->id->value)->exists())->toBeTrue();
});

/**
 * @return array{actor: Actor, person: Person, type: RelationshipType, id: string}
 */
function contendedGuardian(string $name, string $decision): array
{
    $actor = relationshipActor();
    $person = Identity::savedPerson($name);
    $type = app(RelationshipCatalog::class)->type('guardian');
    assert($type !== null);
    $created = app(EstablishRelationship::class)($actor, $type, $person->id, true, 'active', [], true, DefaultRoleChoice::from($decision));

    return ['actor' => $actor, 'person' => $person, 'type' => $type, 'id' => $created->id->value];
}

/**
 * @param  array{actor: Actor, person: Person, id: string}  $row
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function raceArguments(array $row, string $revision, array $extra = []): array
{
    return [
        'actor_account' => $row['actor']->accountId->value,
        'actor_person' => $row['actor']->personId->value,
        'person' => $row['person']->id->value,
        'type' => 'guardian',
        'relationship' => $row['id'],
        'revision' => $revision,
        ...$extra,
    ];
}

it('cannot leave an active sourced grant on a relationship an inactivation committed', function () {
    $row = contendedGuardian('Inactivated', 'grant');
    expect(DB::table('sourced_role_grants')->count())->toBe(1);

    $race = Race::against(function (Closure $pause) use ($row): void {
        RelationshipsPauses::after('lock', $pause);
        app(ChangeRelationshipStatus::class)($row['actor'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), 1, 'inactive', false);
    }, null, 'relationship_default_role', raceArguments($row, '1', ['default_role' => 'grant']));

    expect($race['blocked'])->toBeTrue()
        ->and($race['class'])->toBe(StaleRelationshipRevision::class)
        ->and(DB::table('person_relationships')->where('id', $row['id'])->value('status'))->toBe('inactive')
        ->and(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(DB::table('person_relationship_role_provisions')->value('decision'))->toBe('granted');
});

it('keeps a grant that committed before a stale inactivation', function () {
    $row = contendedGuardian('Granted First', 'defer');

    $race = Race::against(function (Closure $pause) use ($row): void {
        RelationshipsPauses::after('lock', $pause);
        app(DecideDefaultRole::class)($row['actor'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), 1, DefaultRoleChoice::Grant);
    }, null, 'relationship_status', raceArguments($row, '1', ['status' => 'inactive']));

    expect($race['blocked'])->toBeTrue()
        ->and($race['class'])->toBe(StaleRelationshipRevision::class)
        ->and(DB::table('person_relationships')->where('id', $row['id'])->value('status'))->toBe('active')
        ->and(DB::table('sourced_role_grants')->where('source_id', $row['id'])->count())->toBe(1);

    app(ChangeRelationshipStatus::class)($row['actor'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), 2, 'inactive', false);
    expect(DB::table('person_relationships')->where('id', $row['id'])->value('status'))->toBe('inactive')
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('lets one of two grants for one relationship commit', function () {
    $row = contendedGuardian('Two Grants', 'defer');

    $race = Race::against(function (Closure $pause) use ($row): void {
        RelationshipsPauses::after('lock', $pause);
        app(DecideDefaultRole::class)($row['actor'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), 1, DefaultRoleChoice::Grant);
    }, null, 'relationship_default_role', raceArguments($row, '1', ['default_role' => 'grant']));

    expect($race['blocked'])->toBeTrue()
        ->and($race['class'])->toBe(StaleRelationshipRevision::class)
        ->and(DB::table('sourced_role_grants')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'role.granted')->count())->toBe(1)
        ->and(DB::table('person_relationships')->where('id', $row['id'])->value('revision'))->toBe(2);
});

it('does not let reactivation restore a relationship a deletion removed', function () {
    $actor = relationshipActor();
    $person = Identity::savedPerson('Gone');
    $type = app(RelationshipCatalog::class)->type('guardian');
    assert($type !== null);
    $created = app(EstablishRelationship::class)($actor, $type, $person->id, true, 'inactive', [], false);
    $row = ['actor' => $actor, 'person' => $person, 'type' => $type, 'id' => $created->id->value];

    $race = Race::against(function (Closure $pause) use ($row): void {
        RelationshipsPauses::after('lock', $pause);
        app(DeleteRelationship::class)($row['actor'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), 1);
    }, null, 'relationship_status', raceArguments($row, '1', ['status' => 'active', 'default_role' => 'grant']));

    expect($race['blocked'])->toBeTrue()
        ->and($race['class'])->toBe(RelationshipNotFound::class)
        ->and(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('does not let a stale deletion remove a reactivation that committed', function () {
    $actor = relationshipActor();
    $person = Identity::savedPerson('Returning');
    $type = app(RelationshipCatalog::class)->type('guardian');
    assert($type !== null);
    $created = app(EstablishRelationship::class)($actor, $type, $person->id, true, 'inactive', [], false);
    $row = ['actor' => $actor, 'person' => $person, 'type' => $type, 'id' => $created->id->value];

    $race = Race::against(function (Closure $pause) use ($row): void {
        RelationshipsPauses::after('lock', $pause);
        app(ChangeRelationshipStatus::class)($row['actor'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), 1, 'active', true, DefaultRoleChoice::Grant);
    }, null, 'relationship_delete', raceArguments($row, '1'));

    expect($race['blocked'])->toBeTrue()
        ->and($race['class'])->toBe(StaleRelationshipRevision::class)
        ->and(DB::table('person_relationships')->where('id', $row['id'])->value('status'))->toBe('active')
        ->and(DB::table('sourced_role_grants')->where('person_id', $person->id->value)->count())->toBe(1);
});

it('does not let a grant land on a relationship a deletion removed', function () {
    $row = contendedGuardian('Deleted Under Grant', 'defer');

    $race = Race::against(function (Closure $pause) use ($row): void {
        RelationshipsPauses::after('lock', $pause);
        app(DeleteRelationship::class)($row['actor'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), 1);
    }, null, 'relationship_default_role', raceArguments($row, '1', ['default_role' => 'grant']));

    expect($race['blocked'])->toBeTrue()
        ->and($race['class'])->toBe(RelationshipNotFound::class)
        ->and(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('does not let a stale deletion remove a grant that committed', function () {
    $row = contendedGuardian('Grant Then Delete', 'defer');

    $race = Race::against(function (Closure $pause) use ($row): void {
        RelationshipsPauses::after('lock', $pause);
        app(DecideDefaultRole::class)($row['actor'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), 1, DefaultRoleChoice::Grant);
    }, null, 'relationship_delete', raceArguments($row, '1'));

    expect($race['blocked'])->toBeTrue()
        ->and($race['class'])->toBe(StaleRelationshipRevision::class)
        ->and(DB::table('person_relationships')->where('id', $row['id'])->exists())->toBeTrue()
        ->and(DB::table('sourced_role_grants')->where('source_id', $row['id'])->where('person_id', $row['person']->id->value)->count())->toBe(1);
});

it('lets an independent grant of the same role commit beside a relationship-managed one', function () {
    $row = contendedGuardian('Both Sources', 'defer');

    $race = Race::against(function (Closure $pause) use ($row): void {
        app(DecideDefaultRole::class)($row['actor'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), 1, DefaultRoleChoice::Grant);
    }, 'role.granted', 'grant_role', [
        'actor_account' => $row['actor']->accountId->value,
        'actor_person' => $row['actor']->personId->value,
        'person' => $row['person']->id->value,
        'role' => 'guardian-initiate',
    ]);

    expect($race['blocked'])->toBeFalse()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('sourced_role_grants')->where('person_id', $row['person']->id->value)->count())->toBe(1)
        ->and(DB::table('role_assignments')->where('person_id', $row['person']->id->value)->where('role_key', 'guardian-initiate')->count())->toBe(1);
});

// --- Scoped People access (ADR 0038, P5, P7, T1) ----------------------------------------------------------------------------------

/**
 * A Volunteer with CRM data, and two actors: the scoped editor (the relationship manager) and an ordinary CRM user.
 *
 * @return array{scoped: Actor, crm: Actor, person: Person, type: RelationshipType, id: string, email: ContactMethod, phone: ContactMethod}
 */
function peopleRace(): array
{
    $scoped = relationshipActor();
    $crm = Crm::manager('race.crm@example.org');
    $person = Identity::savedPerson('Race Person');
    $email = Crm::email($crm, $person->id, 'old@example.org');
    $phone = Crm::phone($crm, $person->id, '+1 555 010 0100');
    $type = app(RelationshipCatalog::class)->type('volunteer');
    assert($type !== null);
    $created = app(EstablishRelationship::class)($scoped, $type, $person->id, true, 'active', [], false);

    return ['scoped' => $scoped, 'crm' => $crm, 'person' => $person, 'type' => $type, 'id' => $created->id->value, 'email' => $email, 'phone' => $phone];
}

/**
 * @param  array{scoped: Actor, person: Person, id: string}  $row
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function basicsArguments(array $row, array $payload): array
{
    return [
        'actor_account' => $row['scoped']->accountId->value,
        'actor_person' => $row['scoped']->personId->value,
        'type' => 'volunteer',
        'person' => $row['person']->id->value,
        'relationship' => $row['id'],
        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
    ];
}

/** @param array{person: Person} $row */
function currentMethod(array $row, string $kind): string
{
    $value = DB::table('contact_methods')->where('person_id', $row['person']->id->value)->where('kind', $kind)->whereNotNull('primary_kind')->value('value');
    assert(is_string($value));

    return $value;
}

it('makes a scoped email edit wait for a CRM change in flight, then refuse it as stale, and loses nothing', function () {
    $row = peopleRace();

    $race = Race::against(function (Closure $pause) use ($row): void {
        DB::transaction(function () use ($row, $pause): void {
            app(UpdateContactMethod::class)($row['crm'], $row['person']->id, $row['email']->id, ['value' => 'crm.change@example.org']);
            $pause();
        });
    }, null, 'relationship_basics', basicsArguments($row, ['primary_email' => ['from' => 'old@example.org', 'to' => 'scoped@example.org']]));

    expect($race['blocked'])->toBeTrue('the scoped edit did not wait for the CRM change to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(StaleBasicDetails::class)
        ->and(currentMethod($row, 'email'))->toBe('crm.change@example.org')
        ->and(DB::table('contact_methods')->where('id', $row['email']->id->value)->value('updated_by_account_id'))->toBe($row['crm']->accountId->value)
        ->and(DB::table('contact_methods')->where('person_id', $row['person']->id->value)->count())->toBe(2);
});

it('sequences a CRM email edit behind a scoped one in flight, and the later committed value stands', function () {
    $row = peopleRace();

    $race = Race::against(function (Closure $pause) use ($row): void {
        DB::transaction(function () use ($row, $pause): void {
            app(UpdateRelationshipPersonBasics::class)($row['scoped'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), [
                'primary_email' => ['from' => 'old@example.org', 'to' => 'scoped@example.org'],
            ]);
            $pause();
        });
    }, null, 'crm_update_method', [
        'actor_account' => $row['crm']->accountId->value, 'actor_person' => $row['crm']->personId->value,
        'person' => $row['person']->id->value, 'method' => $row['email']->id->value, 'value' => 'crm.after@example.org',
    ]);

    expect($race['blocked'])->toBeTrue('the CRM edit did not wait for the scoped edit to commit')
        ->and($race['exit'])->toBe(0)
        ->and(currentMethod($row, 'email'))->toBe('crm.after@example.org') // applied after, never lost, never torn
        ->and(DB::table('contact_methods')->where('person_id', $row['person']->id->value)->where('kind', 'email')->count())->toBe(1);
});

it('makes a scoped phone edit wait for a CRM change in flight and refuse it as stale', function () {
    $row = peopleRace();

    $race = Race::against(function (Closure $pause) use ($row): void {
        DB::transaction(function () use ($row, $pause): void {
            app(UpdateContactMethod::class)($row['crm'], $row['person']->id, $row['phone']->id, ['value' => '+1 555 010 0177']);
            $pause();
        });
    }, null, 'relationship_basics', basicsArguments($row, ['primary_phone' => ['from' => '+1 555 010 0100', 'to' => '+1 555 010 0199']]));

    expect($race['blocked'])->toBeTrue('the scoped edit did not wait for the CRM change to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(StaleBasicDetails::class)
        ->and(currentMethod($row, 'phone'))->toBe('+1 555 010 0177');
});

it('makes a scoped name correction wait for a CRM rename in flight, then refuse it as stale', function () {
    $row = peopleRace();

    $race = Race::against(function (Closure $pause) use ($row): void {
        DB::transaction(function () use ($row, $pause): void {
            app(UpdatePerson::class)($row['crm'], $row['person']->id, 'Renamed By CRM', new ProfileChanges);
            $pause();
        });
    }, null, 'relationship_basics', basicsArguments($row, ['display_name' => ['from' => 'Race Person', 'to' => 'Renamed By Scope']]));

    expect($race['blocked'])->toBeTrue('the scoped rename did not wait for the CRM rename to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(StaleBasicDetails::class)
        ->and(DB::table('people')->where('id', $row['person']->id->value)->value('display_name'))->toBe('Renamed By CRM')
        ->and(DB::table('security_events')->where('type', 'person.renamed')->count())->toBe(1);
});

it('sequences a CRM rename behind a scoped name correction in flight', function () {
    $row = peopleRace();

    $race = Race::against(function (Closure $pause) use ($row): void {
        DB::transaction(function () use ($row, $pause): void {
            app(UpdateRelationshipPersonBasics::class)($row['scoped'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), [
                'display_name' => ['from' => 'Race Person', 'to' => 'Renamed By Scope'],
            ]);
            $pause();
        });
    }, null, 'crm_update_person', [
        'actor_account' => $row['crm']->accountId->value, 'actor_person' => $row['crm']->personId->value,
        'person' => $row['person']->id->value, 'name' => 'Renamed By CRM Later',
    ]);

    expect($race['blocked'])->toBeTrue('the CRM rename did not wait for the scoped rename to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('people')->where('id', $row['person']->id->value)->value('display_name'))->toBe('Renamed By CRM Later')
        ->and(DB::table('security_events')->where('type', 'person.renamed')->count())->toBe(2); // both are recorded, in order
});

it('does not let a scoped edit land on a relationship a deletion is removing', function () {
    $row = peopleRace();

    $race = Race::against(function (Closure $pause) use ($row): void {
        RelationshipsPauses::after('lock', $pause);
        app(DeleteRelationship::class)($row['scoped'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), 1);
    }, null, 'relationship_basics', basicsArguments($row, [
        'display_name' => ['from' => 'Race Person', 'to' => 'Edited After Delete'],
        'primary_email' => ['from' => 'old@example.org', 'to' => 'edited@example.org'],
    ]));

    expect($race['blocked'])->toBeTrue('the scoped edit did not wait for the deletion to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(RelationshipNotFound::class)
        ->and(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('people')->where('id', $row['person']->id->value)->value('display_name'))->toBe('Race Person')
        ->and(currentMethod($row, 'email'))->toBe('old@example.org')
        ->and(DB::table('security_events')->where('type', 'person.renamed')->count())->toBe(0);
});

it('lets a deletion wait for a scoped edit in flight, so no edit is half applied around it', function () {
    $row = peopleRace();

    $race = Race::against(function (Closure $pause) use ($row): void {
        DB::transaction(function () use ($row, $pause): void {
            app(UpdateRelationshipPersonBasics::class)($row['scoped'], $row['type'], $row['person']->id, RelationshipId::fromString($row['id']), [
                'primary_email' => ['from' => 'old@example.org', 'to' => 'edited@example.org'],
            ]);
            $pause();
        });
    }, null, 'relationship_delete', [
        'actor_account' => $row['scoped']->accountId->value, 'actor_person' => $row['scoped']->personId->value,
        'type' => 'volunteer', 'person' => $row['person']->id->value, 'relationship' => $row['id'], 'revision' => '1',
    ]);

    // A details edit does not move the revision, so the deletion's revision still matches: it proceeds after the edit commits.
    expect($race['blocked'])->toBeTrue('the deletion did not wait for the scoped edit to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('person_relationships')->count())->toBe(0)
        ->and(currentMethod($row, 'email'))->toBe('edited@example.org');
});

it('lets two intakes of new People with one name both commit: duplicate advice is advisory, not a guarantee', function () {
    $actor = relationshipActor();
    $type = app(RelationshipCatalog::class)->type('volunteer');
    assert($type !== null);

    $race = Race::against(function (Closure $pause) use ($actor, $type): void {
        RelationshipsPauses::after('add', $pause);
        app(EstablishRelationship::class)($actor, $type, null, false, 'pending', [], false, null, new NewPersonDetails('Twin Human', 'twin@example.org'));
    }, null, 'relationship_intake', [
        'actor_account' => $actor->accountId->value, 'actor_person' => $actor->personId->value,
        'type' => 'volunteer', 'status' => 'pending', 'new_person_name' => 'Twin Human', 'new_person_email' => 'twin@example.org',
    ]);

    expect($race['blocked'])->toBeFalse('nothing should serialize two brand-new People')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('people')->where('display_name', 'Twin Human')->count())->toBe(2)
        ->and(DB::table('person_relationships')->where('relationship_type', 'volunteer')->count())->toBe(2)
        ->and(DB::table('contact_methods')->where('value', 'twin@example.org')->count())->toBe(2); // two People, one human: CRM merges later
});
