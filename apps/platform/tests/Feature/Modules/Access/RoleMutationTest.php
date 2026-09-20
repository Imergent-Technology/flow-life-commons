<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\GrantRole;
use App\Modules\Access\Application\RevokeRole;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Application\RoleMutation;
use App\Modules\Access\Application\UnknownPerson;
use App\Modules\Access\Domain\RoleAlreadyAssigned;
use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Modules\Identity\Domain\AccountRepository;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;

function grantRole(): GrantRole
{
    return app(GrantRole::class);
}

function revokeRole(): RevokeRole
{
    return app(RevokeRole::class);
}

/** @return list<string> */
function roleKeysOf(PersonId $person): array
{
    return array_map(fn (RoleAssignment $a): string => $a->roleKey, app(RoleAssignmentRepository::class)->forPerson($person));
}

// --- Grant ------------------------------------------------------------------------------

it('lets an actor who holds access.roles.assign grant a role', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    $result = grantRole()(Access::actorFor($admin), $target->personId, Role::Guardian);

    expect($result)->toBe(RoleMutation::Changed)
        ->and(roleKeysOf($target->personId))->toBe(['guardian']);
});

it('records who granted the role', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    grantRole()(Access::actorFor($admin), $target->personId, Role::Guardian);

    expect(app(RoleAssignmentRepository::class)->forPerson($target->personId)[0]->grantedByAccountId)->toEqual($admin->id);
});

it('grants to a person who has no account yet', function () {
    $admin = Access::admin('admin@example.org');
    $contact = Identity::savedPerson('A Contact');

    grantRole()(Access::actorFor($admin), $contact->id, Role::Guardian);

    expect(roleKeysOf($contact->id))->toBe(['guardian']);
});

it('denies a grant to an actor without access.roles.assign, changing nothing', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    expect(fn () => grantRole()(Access::actorFor($guardian), $target->personId, Role::PlatformAdministrator))->toThrow(AccessDenied::class);

    expect(roleKeysOf($target->personId))->toBe([])
        ->and(Identity::events('role.granted'))->toBe([]);
});

it('denies an actor with no roles at all', function () {
    $nobody = Identity::savedActiveAccount('nobody@example.org', name: 'Nobody');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    expect(fn () => grantRole()(Access::actorFor($nobody), $target->personId, Role::Guardian))->toThrow(AccessDenied::class);
});

it('denies an administrator whose account has since been disabled', function () {
    $admin = Access::admin('admin@example.org');
    $other = Access::admin('other@example.org', 'Other');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    $actor = Access::actorFor($admin);
    app(AccountRepository::class)->save($admin->disable(Identity::now()->modify('+1 day')));

    expect(fn () => grantRole()($actor, $target->personId, Role::Guardian))->toThrow(AccessDenied::class)
        ->and($other->id)->not->toBeNull();
});

it('denies an actor whose administrator role was revoked after they were resolved', function () {
    $admin = Access::admin('admin@example.org');
    $actor = Access::actorFor($admin);
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    Access::revoke($admin, Role::PlatformAdministrator);

    expect(fn () => grantRole()($actor, $target->personId, Role::Guardian))->toThrow(AccessDenied::class);
});

it('refuses a grant to a person who does not exist', function () {
    $admin = Access::admin('admin@example.org');

    expect(fn () => grantRole()(Access::actorFor($admin), PersonId::generate(), Role::Guardian))->toThrow(UnknownPerson::class)
        ->and(Identity::events('role.granted'))->toBe([]);
});

it('treats a duplicate grant as a successful no-op that changes and records nothing', function () {
    $admin = Access::admin('admin@example.org');
    $other = Access::admin('other@example.org', 'Other');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    grantRole()(Access::actorFor($admin), $target->personId, Role::Guardian);

    $again = grantRole()(Access::actorFor($other), $target->personId, Role::Guardian);

    expect($again)->toBe(RoleMutation::Unchanged)
        ->and(DB::table('role_assignments')->where('person_id', $target->personId->value)->count())->toBe(1)
        ->and(Identity::events('role.granted'))->toHaveCount(1)
        // The original grant, and who made it, is untouched by the later no-op.
        ->and(app(RoleAssignmentRepository::class)->forPerson($target->personId)[0]->grantedByAccountId)->toEqual($admin->id);
});

it('treats losing a race with an identical grant as a no-op too', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    $real = app(RoleAssignmentRepository::class);
    app()->instance(RoleAssignmentRepository::class, new class($real, $target->personId) implements RoleAssignmentRepository
    {
        public function __construct(private RoleAssignmentRepository $inner, private PersonId $hidden) {}

        public function forPerson(PersonId $personId): array
        {
            // The duplicate check saw nothing for the target (the actor's own roles stay visible, so
            // authorization still works)...
            return $personId->equals($this->hidden) ? [] : $this->inner->forPerson($personId);
        }

        public function add(RoleAssignment $assignment): void
        {
            throw new RoleAlreadyAssigned; // ...but the constraint said otherwise
        }

        public function remove(PersonId $personId, string $roleKey): bool
        {
            return $this->inner->remove($personId, $roleKey);
        }

        public function holdersOf(string $roleKey): array
        {
            return $this->inner->holdersOf($roleKey);
        }

        public function lockHoldersOf(string $roleKey): array
        {
            return $this->inner->lockHoldersOf($roleKey);
        }
    });

    expect(grantRole()(Access::actorFor($admin), $target->personId, Role::Guardian))->toBe(RoleMutation::Unchanged)
        ->and(Identity::events('role.granted'))->toBe([]);
});

it('audits a real grant with the actor, the subject and only the role in context', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    grantRole()(Access::actorFor($admin), $target->personId, Role::Guardian);

    $events = Identity::events('role.granted');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and($events[0]->subject_person_id)->toBe($target->personId->value)
        ->and(Identity::context($events[0]))->toBe(['role' => 'guardian']);
});

// --- Revoke -----------------------------------------------------------------------------

it('lets an actor who holds access.roles.assign revoke a role', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    Access::grant($target, Role::Guardian);

    $result = revokeRole()(Access::actorFor($admin), $target->personId, Role::Guardian);

    expect($result)->toBe(RoleMutation::Changed)
        ->and(roleKeysOf($target->personId))->toBe([])
        // Row absent = no grant: nothing else is left behind.
        ->and(DB::table('role_assignments')->where('person_id', $target->personId->value)->count())->toBe(0);
});

it('denies a revoke to an actor without access.roles.assign, changing nothing', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);

    expect(fn () => revokeRole()(Access::actorFor($guardian), $guardian->personId, Role::Guardian))->toThrow(AccessDenied::class);

    expect(roleKeysOf($guardian->personId))->toBe(['guardian'])
        ->and(Identity::events('role.revoked'))->toBe([]);
});

it('treats revoking a role that is not held as a successful no-op that records nothing', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    $result = revokeRole()(Access::actorFor($admin), $target->personId, Role::Guardian);

    expect($result)->toBe(RoleMutation::Unchanged)
        ->and(Identity::events('role.revoked'))->toBe([]);
});

it('treats a second revoke of the same role as a no-op', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    Access::grant($target, Role::Guardian);
    revokeRole()(Access::actorFor($admin), $target->personId, Role::Guardian);

    expect(revokeRole()(Access::actorFor($admin), $target->personId, Role::Guardian))->toBe(RoleMutation::Unchanged)
        ->and(Identity::events('role.revoked'))->toHaveCount(1);
});

it('leaves the person\'s other roles alone', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    Access::grant($target, Role::Guardian);
    Access::grant($target, Role::PlatformAdministrator);

    revokeRole()(Access::actorFor($admin), $target->personId, Role::Guardian);

    expect(roleKeysOf($target->personId))->toBe(['platform_administrator']);
});

it('audits a real revoke with the actor, the subject and only the role in context', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    Access::grant($target, Role::Guardian);

    revokeRole()(Access::actorFor($admin), $target->personId, Role::Guardian);

    $events = Identity::events('role.revoked');
    expect($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and($events[0]->subject_person_id)->toBe($target->personId->value)
        ->and(Identity::context($events[0]))->toBe(['role' => 'guardian']);
});

// --- Atomic with the audit event ------------------------------------------------------------------

it('rolls a grant back when the audit write fails', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    app()->bind(SecurityEventWriter::class, fn () => new class implements SecurityEventWriter
    {
        public function append(SecurityEvent $event): void
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => grantRole()(Access::actorFor($admin), $target->personId, Role::Guardian))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(roleKeysOf($target->personId))->toBe([]);
});

it('rolls a revoke back when the audit write fails', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');
    Access::grant($target, Role::Guardian);
    app()->bind(SecurityEventWriter::class, fn () => new class implements SecurityEventWriter
    {
        public function append(SecurityEvent $event): void
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => revokeRole()(Access::actorFor($admin), $target->personId, Role::Guardian))->toThrow(RuntimeException::class);

    expect(roleKeysOf($target->personId))->toBe(['guardian']); // the revoke did not survive its failed audit
});

// --- No way around authorization ---------------------------------------------------------------------

it('offers no way to skip authorization: no flag, no override, only actor, person and role', function () {
    foreach ([GrantRole::class, RevokeRole::class] as $class) {
        $parameters = array_map(
            fn (ReflectionParameter $p): string => $p->getName().':'.(string) $p->getType(),
            (new ReflectionMethod($class, '__invoke'))->getParameters(),
        );

        expect($parameters)->toBe(['actor:App\Shared\Domain\Actor', 'person:App\Shared\Domain\PersonId', 'role:App\Modules\Access\Application\Role']);
    }
});

it('names no role to callers: the use cases take a Role from the catalog, never a string', function () {
    // A key that is not in the catalog cannot even be expressed as an argument.
    $arguments = [Access::actorFor(Access::admin('a@example.org')), PersonId::generate(), 'made_up_role'];

    expect(fn () => (new ReflectionMethod(GrantRole::class, '__invoke'))->invoke(grantRole(), ...$arguments))->toThrow(TypeError::class);
});
