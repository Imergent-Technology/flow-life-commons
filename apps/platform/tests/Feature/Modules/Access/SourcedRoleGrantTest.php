<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\GrantRole;
use App\Modules\Access\Application\GrantSourcedRole;
use App\Modules\Access\Application\ListSourcedRoleGrants;
use App\Modules\Access\Application\PeopleHoldingCapability;
use App\Modules\Access\Application\ProvisionableRole;
use App\Modules\Access\Application\RevokeRole;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Application\RoleGrantSource;
use App\Modules\Access\Application\RoleGrantSourceType;
use App\Modules\Access\Application\RoleMutation;
use App\Modules\Access\Application\SourcedGrantBoundToAnotherPerson;
use App\Modules\Access\Application\SourcedRoleGrantsOf;
use App\Modules\Access\Application\UnknownPerson;
use App\Modules\Access\Application\WithdrawSourcedRoles;
use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Modules\Relationships\Application\NoRelationshipGrants;
use App\Modules\Relationships\Application\RelationshipGrantWithdrawal;
use App\Modules\Resources\Application\BrowseResourceLibrary;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Mfa;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');
});

function sourceOf(string $id): RoleGrantSource
{
    return RoleGrantSource::relationship($id);
}

it('grants a provisionable role only to an actor who may assign roles, and records the source', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson('No Account');
    $source = sourceOf(strtolower((string) Str::ulid()));

    $result = app(GrantSourcedRole::class)(Access::actorFor($admin), $person->id, ProvisionableRole::GuardianInitiate, $source);

    $events = Identity::events('role.granted');
    expect($result)->toBe(RoleMutation::Changed)
        ->and(DB::table('accounts')->where('person_id', $person->id->value)->count())->toBe(0)
        ->and(DB::table('role_assignments')->where('person_id', $person->id->value)->count())->toBe(0)
        ->and(DB::table('person_relationships')->where('person_id', $person->id->value)->count())->toBe(0)
        ->and(DB::table('sourced_role_grants')->where('person_id', $person->id->value)->value('role_key'))->toBe('guardian-initiate')
        ->and($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and($events[0]->subject_person_id)->toBe($person->id->value)
        ->and(Identity::context($events[0]))->toBe([
            'role' => 'guardian-initiate',
            'source_type' => 'relationship',
            'source_id' => $source->id,
        ]);
});

it('refuses an actor without access.roles.assign and writes nothing', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org');
    Access::grant($guardian, Role::GuardianFull);
    $person = Identity::savedPerson('Target');
    $source = sourceOf(strtolower((string) Str::ulid()));

    expect(fn () => app(GrantSourcedRole::class)(Access::actorFor($guardian), $person->id, ProvisionableRole::GuardianInitiate, $source))
        ->toThrow(AccessDenied::class);

    expect(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(Identity::events('role.granted'))->toBe([]);
});

it('refuses a person who does not exist', function () {
    $admin = Access::admin('admin@example.org');

    expect(fn () => app(GrantSourcedRole::class)(
        Access::actorFor($admin), PersonId::generate(), ProvisionableRole::GuardianInitiate, sourceOf(strtolower((string) Str::ulid())),
    ))->toThrow(UnknownPerson::class);

    expect(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('accepts only a provisionable role, which cannot be the platform administrator', function () {
    $parameter = (new ReflectionMethod(GrantSourcedRole::class, '__invoke'))->getParameters()[2]->getType();
    assert($parameter instanceof ReflectionNamedType);

    expect($parameter->getName())->toBe(ProvisionableRole::class)
        ->and(array_map(fn (ProvisionableRole $role) => $role->role(), ProvisionableRole::cases()))
        ->not->toContain(Role::PlatformAdministrator);
});

it('is idempotent for the same person and source, and refuses to retarget the source', function () {
    $admin = Access::admin('admin@example.org');
    $other = Access::admin('other@example.org', 'Other');
    $first = Identity::savedPerson('First');
    $second = Identity::savedPerson('Second');
    $source = sourceOf(strtolower((string) Str::ulid()));

    expect(app(GrantSourcedRole::class)(Access::actorFor($admin), $first->id, ProvisionableRole::GuardianInitiate, $source))->toBe(RoleMutation::Changed);
    expect(app(GrantSourcedRole::class)(Access::actorFor($other), $first->id, ProvisionableRole::GuardianInitiate, $source))->toBe(RoleMutation::Unchanged);
    expect(fn () => app(GrantSourcedRole::class)(Access::actorFor($admin), $second->id, ProvisionableRole::GuardianInitiate, $source))
        ->toThrow(SourcedGrantBoundToAnotherPerson::class);

    expect(DB::table('sourced_role_grants')->count())->toBe(1)
        ->and(DB::table('sourced_role_grants')->value('person_id'))->toBe($first->id->value)
        ->and(DB::table('sourced_role_grants')->value('granted_by_account_id'))->toBe($admin->id->value)
        ->and(Identity::events('role.granted'))->toHaveCount(1);
});

it('keeps two sources of the same role, and a manual assignment, independent of one withdrawal', function () {
    $admin = Access::admin('admin@example.org');
    $account = Identity::savedActiveAccount('holder@example.org');
    $first = sourceOf(strtolower((string) Str::ulid()));
    $second = sourceOf(strtolower((string) Str::ulid()));
    app(GrantRole::class)(Access::actorFor($admin), $account->personId, Role::GuardianFull);
    app(GrantSourcedRole::class)(Access::actorFor($admin), $account->personId, ProvisionableRole::GuardianInitiate, $first);
    app(GrantSourcedRole::class)(Access::actorFor($admin), $account->personId, ProvisionableRole::GuardianInitiate, $second);

    $actor = Access::actorFor($account);
    expect(app(Authorizer::class)->allows($actor, Capability::ViewResources))->toBeTrue()
        ->and(app(Authorizer::class)->allows($actor, Capability::ConsoleAccess))->toBeTrue();

    expect(app(WithdrawSourcedRoles::class)(Access::actorFor($admin), $first))->toBe(RoleMutation::Changed);

    expect(DB::table('sourced_role_grants')->count())->toBe(1)
        ->and(DB::table('sourced_role_grants')->value('source_id'))->toBe($second->id)
        ->and(DB::table('role_assignments')->where('person_id', $account->personId->value)->pluck('role_key')->all())->toBe(['guardian-full'])
        ->and(app(Authorizer::class)->allows($actor, Capability::ViewResources))->toBeTrue();

    app(RevokeRole::class)(Access::actorFor($admin), $account->personId, Role::GuardianFull);

    expect(DB::table('sourced_role_grants')->count())->toBe(1)
        ->and(app(Authorizer::class)->capabilitiesOf($actor))->toBe([Capability::ConsoleAccess])
        ->and(app(Authorizer::class)->allows($actor, Capability::ViewResources))->toBeFalse();

    expect(app(WithdrawSourcedRoles::class)(Access::actorFor($admin), $second))->toBe(RoleMutation::Changed)
        ->and(app(WithdrawSourcedRoles::class)(Access::actorFor($admin), $second))->toBe(RoleMutation::Unchanged)
        ->and(app(Authorizer::class)->capabilitiesOf($actor))->toBe([])
        ->and(Identity::events('role.revoked'))->toHaveCount(3);
});

it('lets an actor without role-assignment authority withdraw a source', function () {
    $admin = Access::admin('admin@example.org');
    $guardian = Identity::savedActiveAccount('guardian@example.org');
    Access::grant($guardian, Role::GuardianFull);
    $person = Identity::savedPerson('Granted');
    $source = sourceOf(strtolower((string) Str::ulid()));
    app(GrantSourcedRole::class)(Access::actorFor($admin), $person->id, ProvisionableRole::GuardianInitiate, $source);

    expect(app(WithdrawSourcedRoles::class)(Access::actorFor($guardian), $source))->toBe(RoleMutation::Changed)
        ->and(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('rolls the grant and its audit event back together, including when the caller aborts', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson('Rolled');
    $source = sourceOf(strtolower((string) Str::ulid()));
    app()->bind(SecurityEventWriter::class, fn () => new class implements SecurityEventWriter
    {
        public function append(SecurityEvent $event): void
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => app(GrantSourcedRole::class)(Access::actorFor($admin), $person->id, ProvisionableRole::GuardianInitiate, $source))
        ->toThrow(RuntimeException::class, 'audit store unavailable');
    expect(DB::table('sourced_role_grants')->count())->toBe(0);
});

it('joins the caller transaction, so a later failure keeps the grant from committing', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson('Outer');
    $source = sourceOf(strtolower((string) Str::ulid()));

    expect(function () use ($admin, $person, $source): void {
        DB::transaction(function () use ($admin, $person, $source): void {
            app(GrantSourcedRole::class)(Access::actorFor($admin), $person->id, ProvisionableRole::GuardianInitiate, $source);
            throw new RuntimeException('caller failed');
        });
    })->toThrow(RuntimeException::class, 'caller failed');

    expect(DB::table('sourced_role_grants')->count())->toBe(0)
        ->and(Identity::events('role.granted'))->toBe([]);
});

it('applies a sourced role only through the account linked to that person', function () {
    $admin = Access::admin('admin@example.org');
    $holder = Identity::savedActiveAccount('holder@example.org');
    $other = Identity::savedActiveAccount('other@example.org');
    $dormant = Identity::savedPerson('Dormant');
    $source = sourceOf(strtolower((string) Str::ulid()));
    app(GrantSourcedRole::class)(Access::actorFor($admin), $holder->personId, ProvisionableRole::GuardianInitiate, $source);
    app(GrantSourcedRole::class)(Access::actorFor($admin), $dormant->id, ProvisionableRole::GuardianInitiate, sourceOf(strtolower((string) Str::ulid())));

    expect(app(Authorizer::class)->capabilitiesOf(Access::actorFor($holder)))->toBe([Capability::ConsoleAccess])
        ->and(app(Authorizer::class)->capabilitiesOf(Access::actorFor($other)))->toBe([])
        ->and(app(Authorizer::class)->allows(Actor::user($other->id, $holder->personId), Capability::ConsoleAccess))->toBeFalse()
        ->and(DB::table('accounts')->where('person_id', $dormant->id->value)->exists())->toBeFalse();
});

it('does not let a console-only role read the resource library, or create a relationship', function () {
    $admin = Access::admin('admin@example.org');
    $account = Identity::savedActiveAccount('initiate@example.org');
    app(GrantSourcedRole::class)(
        Access::actorFor($admin), $account->personId, ProvisionableRole::GuardianInitiate, sourceOf(strtolower((string) Str::ulid())),
    );
    Access::grant($account, Role::ConsoleParticipant);

    expect(fn () => app(BrowseResourceLibrary::class)(Access::actorFor($account), null, null))->toThrow(AccessDenied::class)
        ->and(app(Authorizer::class)->allows(Access::actorFor($account), Capability::ViewResources))->toBeFalse()
        ->and(app(Authorizer::class)->allows(Access::actorFor($account), Capability::ManageResources))->toBeFalse()
        ->and(DB::table('person_relationships')->count())->toBe(0);
});

it('lists grants with the person they were stored for, and finds holders from either table once', function () {
    $admin = Access::admin('admin@example.org');
    $account = Identity::savedActiveAccount('holder@example.org');
    $source = sourceOf(strtolower((string) Str::ulid()));
    app(GrantSourcedRole::class)(Access::actorFor($admin), $account->personId, ProvisionableRole::GuardianInitiate, $source);
    Access::grant($account, Role::ConsoleParticipant);
    Access::plant($account->personId, 'retired_role');

    $listed = app(ListSourcedRoleGrants::class)(RoleGrantSourceType::Relationship);
    $of = app(SourcedRoleGrantsOf::class)([$source, sourceOf(strtolower((string) Str::ulid()))]);
    $holders = array_map(fn (PersonId $id) => $id->value, app(PeopleHoldingCapability::class)(Capability::ConsoleAccess));

    expect($listed)->toHaveCount(1)
        ->and($listed[0]->personId->value)->toBe($account->personId->value)
        ->and($listed[0]->roleKey)->toBe('guardian-initiate')
        ->and($listed[0]->sourceId)->toBe($source->id)
        ->and($of)->toHaveCount(1)
        // The administrator holds every capability, including console admission. The account holds it
        // through two roles and is still one person. The planted retired key grants nothing.
        ->and($holders)->toEqualCanonicalizing([$account->personId->value, $admin->personId->value]);
});

it('shows sourced grants apart from assignments and does not offer them to revoke', function () {
    $admin = Access::admin('admin@example.org');
    $account = Identity::savedActiveAccount('holder@example.org');
    $source = sourceOf(strtolower((string) Str::ulid()));
    Access::grant($account, Role::GuardianFull);
    app(GrantSourcedRole::class)(Access::actorFor($admin), $account->personId, ProvisionableRole::GuardianInitiate, $source);

    [$console] = Mfa::signedInAdmin('operator@example.org');
    $body = $console->get('/api/v1/admin/accounts/'.$account->id->value)->assertOk()->json();
    assert(is_array($body));
    $assignments = $body['assignments'];
    $grants = $body['sourced_grants'];
    assert(is_array($assignments) && is_array($grants));
    $assignment = $assignments[0];
    $grant = $grants[0];
    assert(is_array($assignment) && is_array($grant));

    expect($assignment['key'])->toBe('guardian-full')
        ->and($grant['key'])->toBe('guardian-initiate')
        ->and($grant['source_label'])->toBe('Granted through a relationship')
        ->and($grant['source_id'])->toBe($source->id)
        ->and($grant)->not->toHaveKey('revoke');

    $console->delete('/api/v1/admin/accounts/'.$account->id->value.'/assignments/guardian-full')->assertOk();
    expect(DB::table('sourced_role_grants')->count())->toBe(1)
        ->and(DB::table('role_assignments')->where('person_id', $account->personId->value)->count())->toBe(0);
});

it('keeps the relationship withdrawal adapter a no-op until WP2B', function () {
    expect(app(RelationshipGrantWithdrawal::class))
        ->toBeInstanceOf(NoRelationshipGrants::class);
});
