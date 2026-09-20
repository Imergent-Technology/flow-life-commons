<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Domain\AccountRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Tests\Support\Access;
use Tests\Support\Identity;

function authorizer(): Authorizer
{
    return app(Authorizer::class);
}

/** @return list<string> */
function held(Actor $actor): array
{
    return array_map(fn (Capability $c): string => $c->value, authorizer()->capabilitiesOf($actor));
}

// --- Default deny ----------------------------------------------------------------------

it('denies everything to an actor with no assignments', function () {
    $actor = Access::actorFor(Identity::savedActiveAccount());

    foreach (Capability::cases() as $capability) {
        expect(authorizer()->allows($actor, $capability))->toBeFalse($capability->value);
    }
    expect(authorizer()->capabilitiesOf($actor))->toBe([]);
});

it('grants a role only the capabilities that role defines', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::Guardian);
    $actor = Access::actorFor($account);

    expect(authorizer()->allows($actor, Capability::ConsoleAccess))->toBeTrue()
        // An unrelated capability stays denied.
        ->and(authorizer()->allows($actor, Capability::AssignRoles))->toBeFalse()
        ->and(held($actor))->toBe(['console.access']);
});

it('gives the platform administrator every defined capability', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::PlatformAdministrator);
    $actor = Access::actorFor($account);

    foreach (Capability::cases() as $capability) {
        expect(authorizer()->allows($actor, $capability))->toBeTrue($capability->value);
    }
    expect(count(authorizer()->capabilitiesOf($actor)))->toBe(count(Capability::cases()));
});

it('unions the capabilities of several roles, in a stable order', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::Guardian);
    Access::grant($account, Role::PlatformAdministrator);

    expect(held(Access::actorFor($account)))->toBe(Access::everyCapabilityId());
});

it('reports the same capabilities however the assignments were made', function () {
    $ascending = Identity::savedActiveAccount('a@example.org');
    Access::grant($ascending, Role::Guardian);
    Access::grant($ascending, Role::PlatformAdministrator);
    $descending = Identity::savedActiveAccount('b@example.org', name: 'B');
    Access::grant($descending, Role::PlatformAdministrator);
    Access::grant($descending, Role::Guardian);

    expect(held(Access::actorFor($ascending)))->toBe(held(Access::actorFor($descending)));
});

it('never lets one person\'s roles reach another', function () {
    $admin = Identity::savedActiveAccount('admin@example.org');
    $other = Identity::savedActiveAccount('other@example.org', name: 'Other');
    Access::grant($admin, Role::PlatformAdministrator);

    expect(held(Access::actorFor($other)))->toBe([]);
});

it('answers allows and capabilitiesOf consistently for every role and capability', function () {
    foreach (['none' => null, 'guardian' => Role::Guardian, 'admin' => Role::PlatformAdministrator] as $label => $role) {
        $account = Identity::savedActiveAccount("{$label}@example.org", name: $label);
        if ($role !== null) {
            Access::grant($account, $role);
        }
        $actor = Access::actorFor($account);
        $held = authorizer()->capabilitiesOf($actor);

        foreach (Capability::cases() as $capability) {
            expect(authorizer()->allows($actor, $capability))->toBe(in_array($capability, $held, true), "{$label} / {$capability->value}");
        }
    }
});

// --- Live lookup: nothing cached, nothing snapshotted -------------------------------------------

it('reflects a role removed after the actor was resolved, on the very next decision', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::PlatformAdministrator);
    $actor = Access::actorFor($account);
    expect(authorizer()->allows($actor, Capability::AssignRoles))->toBeTrue();

    Access::revoke($account, Role::PlatformAdministrator);

    // The SAME actor object: nothing about it preserved the privilege.
    expect(authorizer()->allows($actor, Capability::AssignRoles))->toBeFalse()
        ->and(authorizer()->capabilitiesOf($actor))->toBe([]);
});

it('reflects a role added after the actor was resolved, without recreating it', function () {
    $account = Identity::savedActiveAccount();
    $actor = Access::actorFor($account);
    expect(authorizer()->allows($actor, Capability::ConsoleAccess))->toBeFalse();

    Access::grant($account, Role::Guardian);

    expect(authorizer()->allows($actor, Capability::ConsoleAccess))->toBeTrue();
});

it('narrows a person to what remains when one of two roles is revoked', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::Guardian);
    Access::grant($account, Role::PlatformAdministrator);
    $actor = Access::actorFor($account);

    Access::revoke($account, Role::PlatformAdministrator);

    expect(held($actor))->toBe(['console.access']);
});

it('carries no capability snapshot in the actor', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::PlatformAdministrator);
    $actor = Access::actorFor($account);

    // Serialising the actor exposes identity and provenance only: nothing that could be replayed as a grant.
    $serialised = json_encode(get_object_vars($actor), JSON_THROW_ON_ERROR);
    foreach (['console.access', 'platform_administrator', 'assign', 'capabilit', 'role'] as $needle) {
        expect($serialised)->not->toContain($needle);
    }
});

// --- The account must still be able to authenticate --------------------------------------------

it('denies an actor whose account has since been disabled, even for an administrator', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::PlatformAdministrator);
    $actor = Access::actorFor($account);
    expect(authorizer()->allows($actor, Capability::AssignRoles))->toBeTrue();

    app(AccountRepository::class)->save($account->disable(Identity::now()->modify('+1 day')));

    expect(authorizer()->allows($actor, Capability::AssignRoles))->toBeFalse()
        ->and(authorizer()->capabilitiesOf($actor))->toBe([]);
});

it('denies an actor for an account that has never activated', function () {
    $invited = Identity::savedInvitedAccount();
    Access::grant($invited, Role::PlatformAdministrator);

    expect(authorizer()->allows(Access::actorFor($invited), Capability::AssignRoles))->toBeFalse();
});

it('denies a forged actor for an account that does not exist', function () {
    $actor = Actor::user(AccountId::generate(), PersonId::generate());

    expect(authorizer()->allows($actor, Capability::ConsoleAccess))->toBeFalse();
});

it('does not trust the person an actor claims: an actor for an ordinary account naming an administrator\'s person is denied', function () {
    $admin = Identity::savedActiveAccount('admin@example.org');
    Access::grant($admin, Role::PlatformAdministrator);
    $ordinary = Identity::savedActiveAccount('ordinary@example.org', name: 'Ordinary');

    $forged = Actor::user($ordinary->id, $admin->personId);

    expect(authorizer()->allows($forged, Capability::AssignRoles))->toBeFalse()
        ->and(authorizer()->capabilitiesOf($forged))->toBe([]);
});

it('does not let an administrator\'s account id carry another person\'s claim either', function () {
    $admin = Identity::savedActiveAccount('admin@example.org');
    Access::grant($admin, Role::PlatformAdministrator);
    $stranger = PersonId::generate();

    expect(authorizer()->allows(Actor::user($admin->id, $stranger), Capability::AssignRoles))->toBeFalse();
});

// --- Fail closed on bad persisted data ------------------------------------------------------------

it('grants nothing for an unknown, obsolete or wrongly-cased role key', function (string $key) {
    $account = Identity::savedActiveAccount();
    Access::plant($account->personId, $key);

    expect(authorizer()->capabilitiesOf(Access::actorFor($account)))->toBe([]);
})->with(['retired_role', 'PLATFORM_ADMINISTRATOR', 'Platform_Administrator', 'platform_administrator ', ' guardian', '', 'is_admin', 'admin']);

it('ignores a bad key without disturbing the person\'s valid roles', function () {
    $account = Identity::savedActiveAccount();
    Access::plant($account->personId, 'retired_role');
    Access::grant($account, Role::Guardian);

    expect(held(Access::actorFor($account)))->toBe(['console.access']);
});

it('never grants on an obsolete key, even one that once meant administrator', function () {
    $account = Identity::savedActiveAccount();
    Access::plant($account->personId, 'super_admin');

    expect(authorizer()->allows(Access::actorFor($account), Capability::AssignRoles))->toBeFalse();
});

// --- AuthorizeAction --------------------------------------------------------------------------------

it('lets business code require a capability, returning quietly when held', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::Guardian);

    app(AuthorizeAction::class)(Access::actorFor($account), Capability::ConsoleAccess);

    expect(true)->toBeTrue(); // no exception
});

it('throws AccessDenied, saying nothing about why, when the capability is not held', function () {
    $actor = Access::actorFor(Identity::savedActiveAccount());

    expect(fn () => app(AuthorizeAction::class)($actor, Capability::AssignRoles))
        ->toThrow(AccessDenied::class, 'This action is unauthorized.');
});
