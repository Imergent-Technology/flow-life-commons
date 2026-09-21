<?php

declare(strict_types=1);

use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentId;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Tests\Support\Identity;

it('has exactly the capability catalog, so adding one is a deliberate decision', function () {
    // A capability is added when the functionality that checks it exists. Extending this list
    // is meant to be a visible act in review, not a side effect.
    expect(array_map(fn (Capability $c): string => $c->value, Capability::cases()))
        ->toBe([
            'console.access', 'access.roles.assign',
            // Operator administration (ADR 0024): each exists because a route checks it.
            'identity.accounts.view', 'identity.accounts.manage', 'identity.invitations.issue', 'identity.mfa.recover',
        ]);
});

it('has exactly the initial system roles', function () {
    expect(array_map(fn (Role $r): string => $r->value, Role::cases()))->toBe(['platform_administrator', 'guardian']);
});

it('names capabilities in dotted lowercase, uniquely', function () {
    $values = array_map(fn (Capability $c): string => $c->value, Capability::cases());

    foreach ($values as $value) {
        expect($value)->toMatch('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D');
    }
    expect(array_unique($values))->toHaveCount(count($values));
});

it('names roles as keys a RoleAssignment accepts', function () {
    foreach (Role::cases() as $role) {
        expect(RoleAssignment::grant(PersonId::generate(), $role->value, null, Identity::now())->roleKey)->toBe($role->value);
    }
});

it('gives the platform administrator every capability, by derivation rather than by listing', function () {
    // Adding a Capability case must reach the administrator without editing Role. This holds
    // because the definition IS Capability::cases(); listing capabilities by hand would break it.
    expect(Role::PlatformAdministrator->capabilities())->toBe(Capability::cases());

    foreach (Capability::cases() as $capability) {
        expect(Role::PlatformAdministrator->grants($capability))->toBeTrue();
    }
});

it('gives the guardian the Console and nothing more', function () {
    expect(Role::Guardian->capabilities())->toBe([Capability::ConsoleAccess])
        ->and(Role::Guardian->grants(Capability::ConsoleAccess))->toBeTrue()
        ->and(Role::Guardian->grants(Capability::AssignRoles))->toBeFalse();
});

it('gives the guardian NO administrative capability: being let into the Console is not being let to administer it', function () {
    foreach ([Capability::AssignRoles, Capability::ViewAccounts, Capability::ManageAccounts, Capability::IssueInvitations, Capability::RecoverMfa] as $administrative) {
        expect(Role::Guardian->grants($administrative))->toBeFalse();
    }
});

it('gives every role a display name and description, which Access owns and the Console renders', function () {
    foreach (Role::cases() as $role) {
        expect($role->displayName())->not->toBe('')->and($role->description())->not->toBe('');
    }
});

it('makes the administrator the only role that resolves to every capability', function () {
    $all = array_filter(Role::cases(), fn (Role $r): bool => count($r->capabilities()) === count(Capability::cases()));

    expect(array_values($all))->toBe([Role::PlatformAdministrator]);
});

it('lists no capability twice in any role', function () {
    foreach (Role::cases() as $role) {
        $values = array_map(fn (Capability $c): string => $c->value, $role->capabilities());
        expect(array_unique($values))->toHaveCount(count($values));
    }
});

it('resolves an unknown, obsolete or wrongly-cased role key to no role at all', function (string $key) {
    expect(Role::tryFrom($key))->toBeNull();
})->with(['', 'retired_role', 'PLATFORM_ADMINISTRATOR', 'Platform_Administrator', 'platform_administrator ', ' guardian', 'admin', 'is_admin']);

// --- RoleAssignment -------------------------------------------------------------------

it('records a grant to a person with optional provenance', function () {
    $person = PersonId::generate();
    $by = AccountId::generate();

    $assignment = RoleAssignment::grant($person, 'guardian', $by, Identity::now());

    expect($assignment->personId)->toBe($person)
        ->and($assignment->roleKey)->toBe('guardian')
        ->and($assignment->grantedByAccountId)->toBe($by)
        ->and($assignment->grantedAt)->toEqual(Identity::now())
        ->and(RoleAssignment::grant($person, 'guardian', null, Identity::now())->grantedByAccountId)->toBeNull();
});

it('refuses to create a grant whose key is not shaped like a role key', function (string $key) {
    RoleAssignment::grant(PersonId::generate(), $key, null, Identity::now());
})->with(['', 'Guardian', 'has space', '1leading', str_repeat('a', 65), 'dot.ted'])->throws(InvalidArgumentException::class);

it('can nevertheless read back a corrupt or obsolete stored key, so authorization can ignore it', function () {
    $assignment = RoleAssignment::reconstitute(
        RoleAssignmentId::generate(), PersonId::generate(), 'PLATFORM_ADMINISTRATOR', null, Identity::now(),
    );

    expect($assignment->roleKey)->toBe('PLATFORM_ADMINISTRATOR');
});

it('has no revoked state, history or scope: a row is an active grant', function () {
    $properties = array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(RoleAssignment::class))->getProperties());

    expect($properties)->toEqualCanonicalizing(['id', 'personId', 'roleKey', 'grantedByAccountId', 'grantedAt']);
});
