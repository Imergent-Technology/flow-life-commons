<?php

declare(strict_types=1);

use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\EnableAccount;
use App\Modules\Identity\Domain\Account;
use App\Modules\Relationships\Application\QualifyingRelationships;
use App\Modules\Resources\Application\AudienceEligibility;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Audiences;
use Tests\Support\Identity;
use Tests\Support\Membership;

/*
 * Who is eligible for which audience (ADR 0038, R10, R12): the union of what the three owners say NOW about the Actor's own Person,
 * after the Actor's identity has been re-resolved. Roles, capabilities and descriptive fields add nothing. Runs on MariaDB and PostgreSQL.
 */

/** @return list<string> the audiences the account's Person is eligible for, in catalog order */
function eligibleFor(Account $account): array
{
    return app(AudienceEligibility::class)(Access::actorFor($account))->values();
}

// --- The three owners, each on its own ---------------------------------------------------------------------------------------

it('makes an Active Guardian eligible for guardian, and a Pending, Inactive or absent one not', function () {
    $account = Audiences::reader([], Role::ConsoleParticipant, 'gil.guardian@example.org');

    expect(eligibleFor($account))->toBe([]);
    Audiences::relate($account->personId, 'guardian', 'inactive');
    expect(eligibleFor($account))->toBe([]);
    Audiences::change($account->personId, 'guardian', 'active');
    expect(eligibleFor($account))->toBe(['guardian']);
    Audiences::change($account->personId, 'guardian', 'inactive');
    expect(eligibleFor($account))->toBe([]);
});

it('makes an Active Volunteer eligible for volunteer, and a Pending or Inactive one not', function () {
    $account = Audiences::reader(['volunteer:pending'], Role::ConsoleParticipant, 'vic.volunteer@example.org');

    expect(eligibleFor($account))->toBe([]);
    Audiences::change($account->personId, 'volunteer', 'active');
    expect(eligibleFor($account))->toBe(['volunteer']);
    Audiences::change($account->personId, 'volunteer', 'inactive');
    expect(eligibleFor($account))->toBe([]);
    Audiences::change($account->personId, 'volunteer', 'pending');
    expect(eligibleFor($account))->toBe([]);
});

it('makes an active Membership eligible for member by Membership\'s own rule, and a lapsed or future one not', function () {
    $account = Audiences::reader([], Role::ConsoleParticipant, 'mia.member@example.org');
    $now = Identity::now();

    Membership::savedGrant($account->personId, $now->modify('-30 days'), $now->modify('-1 day'));
    expect(eligibleFor($account))->toBe([]);
    Membership::savedGrant($account->personId, $now->modify('+10 days'), $now->modify('+20 days'));
    expect(eligibleFor($account))->toBe([]);
    Membership::savedGrant($account->personId, $now->modify('-1 day'), null);
    expect(eligibleFor($account))->toBe(['member']);
});

// --- Union, OR, and no audience implying another -----------------------------------------------------------------------------

it('takes the union of what each owner says, in catalog order: no audience implies another', function (array $relations, array $expected) {
    $account = Audiences::reader(Api::strings($relations), Role::ConsoleParticipant, 'ulla.union@example.org');

    expect(eligibleFor($account))->toBe($expected);
})->with([
    'Guardian only' => [['guardian'], ['guardian']],
    'Volunteer only' => [['volunteer'], ['volunteer']],
    'Member only' => [['member'], ['member']],
    'Guardian and Volunteer' => [['guardian', 'volunteer'], ['guardian', 'volunteer']],
    'Guardian and Member' => [['guardian', 'member'], ['guardian', 'member']],
    'Active Member and Inactive Volunteer' => [['member', 'volunteer:inactive'], ['member']],
    'Active Member and Pending Volunteer' => [['member', 'volunteer:pending'], ['member']],
    'Guardian, Volunteer and Member' => [['guardian', 'volunteer', 'member'], ['guardian', 'member', 'volunteer']],
    'nothing' => [[], []],
]);

// --- A role, a capability or admission is not eligibility --------------------------------------------------------------------

it('derives nothing from a role: every role in the catalog, with no relationship, is eligible for nothing', function (Role $role) {
    $account = Audiences::reader([], $role, "role.{$role->value}@example.org");

    expect(eligibleFor($account))->toBe([]);
})->with(Role::cases());

it('derives nothing from a role even beside a relationship of another kind: a Guardian-named role is not a Guardian, a Volunteer is not a Guardian', function () {
    $senior = Audiences::reader(['volunteer'], Role::GuardianSenior, 'sam.senior@example.org');
    $initiate = Audiences::reader(['member'], Role::GuardianInitiate, 'ida.initiate@example.org');

    expect(eligibleFor($senior))->toBe(['volunteer'])
        ->and(eligibleFor($initiate))->toBe(['member']);
});

it('is eligible by relationship alone: no role, no Console admission and no capability is needed to be a Guardian here', function () {
    $account = Audiences::reader(['guardian'], null, 'rolo.roleless@example.org');

    expect(eligibleFor($account))->toBe(['guardian'])
        ->and(app(Authorizer::class)->capabilitiesOf(Access::actorFor($account)))->toBe([]);
});

it('does not let a relationship-managed Guardian Initiate grant outlive its relationship, nor stand in for it', function () {
    $account = Identity::savedActiveAccount('gia.granted@example.org', name: 'Gia Granted');
    Audiences::relate($account->personId, 'guardian', 'active', grant: true);

    // The grant is real (Console admission) and the relationship is what makes them a Guardian.
    expect(array_map(fn ($c): string => $c->value, app(Authorizer::class)->capabilitiesOf(Access::actorFor($account))))->toBe(['console.access'])
        ->and(eligibleFor($account))->toBe(['guardian']);

    Audiences::change($account->personId, 'guardian', 'inactive');
    expect(app(Authorizer::class)->capabilitiesOf(Access::actorFor($account)))->toBe([])
        ->and(eligibleFor($account))->toBe([]);
});

it('does not read a relationship\'s descriptive fields: setting every field of a Pending Volunteer makes no one eligible', function () {
    $account = Identity::savedActiveAccount('fay.fields@example.org', name: 'Fay Fields');
    Access::grant($account, Role::ConsoleParticipant);
    Audiences::relate($account->personId, 'volunteer', 'pending', fields: ['interests' => 'guardian member active volunteer', 'availability' => 'always, active, eligible']);

    expect(eligibleFor($account))->toBe([]);
});

// --- Current identity, re-resolved ---------------------------------------------------------------------------------------------

it('returns the empty set for a captured Actor whose Account can no longer authenticate, and the set again once it can', function () {
    $account = Audiences::reader(['guardian', 'volunteer', 'member'], Role::ConsoleParticipant, 'cal.captured@example.org');
    $captured = Access::actorFor($account);

    expect(app(AudienceEligibility::class)($captured)->values())->toBe(['guardian', 'member', 'volunteer']);
    app(DisableAccount::class)($account->id);

    // The very same Actor object: it carries no eligibility of its own.
    expect(app(AudienceEligibility::class)($captured)->values())->toBe([]);
    app(EnableAccount::class)($account->id);
    expect(app(AudienceEligibility::class)($captured)->values())->toBe(['guardian', 'member', 'volunteer']);
});

it('returns the empty set for an Actor whose Person is not the Account\'s Person, and for an Account that does not exist', function () {
    $eligible = Audiences::reader(['guardian'], Role::ConsoleParticipant, 'ena.eligible@example.org');
    $other = Audiences::reader([], Role::ConsoleParticipant, 'otto.other@example.org');
    $service = app(AudienceEligibility::class);

    // The eligible Person's id presented with someone else's Account (and the reverse): neither carries eligibility.
    expect($service(Actor::user($other->id, $eligible->personId))->values())->toBe([])
        ->and($service(Actor::user($eligible->id, $other->personId))->values())->toBe([])
        ->and($service(Actor::user(AccountId::generate(), $eligible->personId))->values())->toBe([])
        ->and($service(Actor::user($eligible->id, PersonId::generate()))->values())->toBe([])
        ->and($service(Access::actorFor($eligible))->values())->toBe(['guardian']);
});

it('returns the empty set for an Account that has not yet been activated', function () {
    $person = Identity::savedPerson('Ian Invited');
    $invited = Identity::savedInvitedAccount('ian.invited@example.org');
    Audiences::relate($invited->personId, 'guardian');

    expect(app(AudienceEligibility::class)(Actor::user($invited->id, $invited->personId))->values())->toBe([])
        ->and($person->displayName)->toBe('Ian Invited');
});

// --- Read on every call -----------------------------------------------------------------------------------------------------------

it('reads current state on every call: nothing is remembered between two calls of the same service', function () {
    $account = Audiences::reader(['guardian'], Role::ConsoleParticipant, 'rex.revoked@example.org');
    $service = app(AudienceEligibility::class);
    $actor = Access::actorFor($account);

    expect($service($actor)->values())->toBe(['guardian']);
    Audiences::change($account->personId, 'guardian', 'inactive');
    expect($service($actor)->values())->toBe([]);
    Audiences::change($account->personId, 'guardian', 'active');
    Audiences::remove($account->personId, 'guardian');
    expect($service($actor)->values())->toBe([]);
    DB::table('membership_grants')->delete();
    Membership::savedGrant($account->personId);
    expect($service($actor)->values())->toBe(['member'])
        ->and(count((new ReflectionClass(AudienceEligibility::class))->getProperties(ReflectionProperty::IS_STATIC)))->toBe(0);
});

it('ignores a stored relationship type the catalog does not know, and lists no audience a type has no row for', function () {
    $account = Audiences::reader(['volunteer'], Role::ConsoleParticipant, 'una.unknown@example.org');
    DB::table('person_relationships')->insert([
        'id' => strtolower((string) Str::ulid()), 'person_id' => $account->personId->value, 'relationship_type' => 'partner',
        'status' => 'active', 'revision' => 1, 'status_changed_at' => '2026-10-01 00:00:00', 'status_changed_by' => $account->personId->value,
        'created_at' => '2026-10-01 00:00:00', 'created_by' => $account->personId->value, 'updated_at' => '2026-10-01 00:00:00', 'updated_by' => $account->personId->value,
    ]);

    expect(eligibleFor($account))->toBe(['volunteer'])
        ->and(array_map(fn ($t): string => $t->key, app(QualifyingRelationships::class)($account->personId)))->toBe(['volunteer']);
});
