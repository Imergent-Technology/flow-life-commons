<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Relationships\Application\ChangeRelationshipStatus;
use App\Modules\Relationships\Application\DecideDefaultRole;
use App\Modules\Relationships\Application\DefaultRoleChoice;
use App\Modules\Relationships\Application\DeleteRelationship;
use App\Modules\Relationships\Application\EstablishRelationship;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Application\RelationshipExists;
use App\Modules\Relationships\Application\RelationshipNotFound;
use App\Modules\Relationships\Application\StaleRelationshipRevision;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Shared\Domain\Actor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
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
 * @return array{actor: Actor, person: \App\Modules\Identity\Domain\Person, type: \App\Modules\Relationships\Domain\RelationshipType, id: string}
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

/** @param  array{actor: Actor, person: \App\Modules\Identity\Domain\Person, id: string}  $row */
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
