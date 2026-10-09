<?php

declare(strict_types=1);

use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\ProvisionableRole;
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
            // Membership Foundation (ADR 0028): each exists because a Membership use case checks it.
            'membership.records.view', 'membership.records.manage',
            // The People directory and CRM data (ADR 0034).
            'crm.people.view', 'crm.people.manage',
            // Guardian Discussions (ADR 0035): read, and take part. There is deliberately no discussions.manage.
            'discussions.view', 'discussions.participate',
            // Resources (ADR 0037): read the Guardian library, and manage. There is deliberately no resources.delete or
            // .publish: permanent deletion is the same capability behind recent verification, a route-level layer.
            'resources.view', 'resources.manage',
            // Organizational relationships (ADR 0038): one pair per type, added because the relationship routes check them.
            'guardians.view', 'guardians.manage', 'volunteers.view', 'volunteers.manage',
        ]);
});

it('has exactly the initial system roles', function () {
    expect(array_map(fn (Role $r): string => $r->value, Role::cases()))->toBe([
        'platform_administrator', 'guardian-full', 'guardian-senior', 'guardian-initiate', 'console-participant',
    ]);
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

it('gives guardian-full today\'s guardian bundle, and guardian-senior the same bundle as a separate role', function () {
    $bundle = [
        Capability::ConsoleAccess, Capability::ViewPeople, Capability::ManagePeople,
        Capability::ViewDiscussions, Capability::ParticipateInDiscussions,
        Capability::ViewResources, Capability::ManageResources,
        Capability::ViewGuardians, Capability::ViewVolunteers, Capability::ManageVolunteers,
    ];

    expect(Role::GuardianFull->capabilities())->toBe($bundle)
        ->and(Role::GuardianSenior->capabilities())->toBe($bundle)
        ->and(Role::GuardianFull)->not->toBe(Role::GuardianSenior)
        ->and(Role::tryFrom('guardian'))->toBeNull();

    foreach ([Role::GuardianFull, Role::GuardianSenior] as $role) {
        expect($role->grants(Capability::ViewResources))->toBeTrue()
            ->and($role->grants(Capability::ManageResources))->toBeTrue()
            ->and($role->grants(Capability::ViewDiscussions))->toBeTrue()
            ->and($role->grants(Capability::ParticipateInDiscussions))->toBeTrue()
            ->and($role->grants(Capability::ConsoleAccess))->toBeTrue()
            ->and($role->grants(Capability::ViewPeople))->toBeTrue()
            ->and($role->grants(Capability::ManagePeople))->toBeTrue()
            ->and($role->grants(Capability::AssignRoles))->toBeFalse()
            ->and($role->grants(Capability::ViewGuardians))->toBeTrue()
            ->and($role->grants(Capability::ManageGuardians))->toBeFalse()
            ->and($role->grants(Capability::ViewVolunteers))->toBeTrue()
            ->and($role->grants(Capability::ManageVolunteers))->toBeTrue();
    }
});

it('gives guardian-initiate and console-participant console admission only', function () {
    foreach ([Role::GuardianInitiate, Role::ConsoleParticipant] as $role) {
        expect($role->capabilities())->toBe([Capability::ConsoleAccess])
            ->and($role->grants(Capability::ViewResources))->toBeFalse()
            ->and($role->grants(Capability::ManageResources))->toBeFalse()
            ->and($role->grants(Capability::ViewPeople))->toBeFalse()
            ->and($role->grants(Capability::ManagePeople))->toBeFalse()
            ->and($role->grants(Capability::AssignRoles))->toBeFalse()
            ->and($role->grants(Capability::ViewGuardians))->toBeFalse()
            ->and($role->grants(Capability::ManageGuardians))->toBeFalse();
    }
});

it('gives guardian-full and guardian-senior NO administrative capability', function () {
    foreach ([Role::GuardianFull, Role::GuardianSenior, Role::GuardianInitiate, Role::ConsoleParticipant] as $role) {
        foreach ([Capability::AssignRoles, Capability::ViewAccounts, Capability::ManageAccounts, Capability::IssueInvitations, Capability::RecoverMfa, Capability::ViewMembershipRecords, Capability::ManageMembershipRecords] as $administrative) {
            expect($role->grants($administrative))->toBeFalse();
        }
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
})->with(['', 'retired_role', 'guardian', 'PLATFORM_ADMINISTRATOR', 'Platform_Administrator', 'platform_administrator ', ' guardian', 'admin', 'is_admin', 'guardian-full ']);

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

it('accepts the approved hyphenated role keys and still refuses a malformed one', function (string $key) {
    expect(RoleAssignment::grant(PersonId::generate(), $key, null, Identity::now())->roleKey)->toBe($key);
})->with(['guardian-full', 'guardian-initiate', 'guardian-senior', 'console-participant', 'a', 'a_b', 'a-b']);

it('refuses to create a grant whose key is not shaped like a role key', function (string $key) {
    RoleAssignment::grant(PersonId::generate(), $key, null, Identity::now());
})->with(['', 'Guardian', 'Guardian-Full', 'has space', '1leading', '-leading', str_repeat('a', 65), 'dot.ted', 'has-Upper'])->throws(InvalidArgumentException::class);

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

it('gives every role that may manage People the right to view them: there is no manage-only role', function () {
    // The People write responses reveal CRM data (profile fields, duplicate candidates, whether a method or tag exists), so
    // the platform's protection is that nobody who may write cannot also read (ADR 0034). The capabilities stay independent
    // checks; this pins the ROLE POLICY. A role that manages without viewing needs the candidate and write-response
    // disclosure designed first, then this test changed deliberately.
    $managers = [];
    foreach (Role::cases() as $role) {
        if ($role->grants(Capability::ManagePeople)) {
            $managers[] = $role->value;
            expect($role->grants(Capability::ViewPeople))->toBeTrue("{$role->value} grants crm.people.manage without crm.people.view (ADR 0034)");
        }
    }

    expect($managers)->toBe(['platform_administrator', 'guardian-full', 'guardian-senior']); // positive control: the loop really covered the managers
});

it('gives every role that may participate in discussions the right to view them: there is no participate-only role', function () {
    // Discussion writes return what they wrote (a reply returns the message, a resolution the discussion), so, as with CRM,
    // the platform's protection is that nobody who may write cannot also read (ADR 0035). The capabilities stay independent
    // checks; this pins the ROLE POLICY. A role that participates without viewing needs those write responses designed
    // first, then this test changed deliberately.
    $participants = [];
    foreach (Role::cases() as $role) {
        if ($role->grants(Capability::ParticipateInDiscussions)) {
            $participants[] = $role->value;
            expect($role->grants(Capability::ViewDiscussions))->toBeTrue("{$role->value} grants discussions.participate without discussions.view (ADR 0035)");
        }
    }

    expect($participants)->toBe(['platform_administrator', 'guardian-full', 'guardian-senior']); // positive control: the loop really covered the participants
});

it('has no discussions.manage capability and no moderation capability: participating never means editing anyone\'s words', function () {
    $discussionCapabilities = array_values(array_filter(
        array_map(fn (Capability $c): string => $c->value, Capability::cases()),
        fn (string $value): bool => str_starts_with($value, 'discussions.'),
    ));

    expect($discussionCapabilities)->toBe(['discussions.view', 'discussions.participate']);
});

it('gives every role that may manage Resources the right to view them: there is no manage-only role', function () {
    // The capabilities stay independent checks (management and the Guardian library are separate routes with separate
    // capabilities); this pins the ROLE POLICY, as for CRM and Discussions. A role that manages without viewing would see
    // Resources in management but be refused the library a Guardian previews against, so a role with one and not the other needs
    // a decision first, then this test changed deliberately.
    $managers = [];
    foreach (Role::cases() as $role) {
        if ($role->grants(Capability::ManageResources)) {
            $managers[] = $role->value;
            expect($role->grants(Capability::ViewResources))->toBeTrue("{$role->value} grants resources.manage without resources.view (ADR 0037)");
        }
    }

    expect($managers)->toBe(['platform_administrator', 'guardian-full', 'guardian-senior']); // positive control: the loop really covered the managers
});

it('has exactly resources.view and resources.manage: no Resources-specific deletion, publishing or Member capability', function () {
    $resourcesCapabilities = array_values(array_filter(
        array_map(fn (Capability $c): string => $c->value, Capability::cases()),
        fn (string $value): bool => str_starts_with($value, 'resources.'),
    ));

    expect($resourcesCapabilities)->toBe(['resources.view', 'resources.manage']);
});

it('does not give a Member, a Volunteer or any relationship a capability: no role is named after one (ADR 0036)', function () {
    // A business relationship is not an Access role. The Volunteer relationship exists (ADR 0038); a role named volunteer does not.
    // The role catalog must therefore hold no `member` or `volunteer` role. guardian-full is a permission
    // bundle; it is not the Guardian relationship.
    expect(array_map(fn (Role $r): string => $r->value, Role::cases()))->not->toContain('member', 'volunteer', 'partner', 'vendor', 'artist', 'guardian');
});

it('lets a source provision exactly guardian-initiate, and that role cannot administer access, identity or Resources', function () {
    expect(array_map(fn (ProvisionableRole $role): string => $role->value, ProvisionableRole::cases()))->toBe(['guardian-initiate'])
        ->and(ProvisionableRole::GuardianInitiate->role())->toBe(Role::GuardianInitiate);

    foreach (ProvisionableRole::cases() as $provisionable) {
        $role = $provisionable->role();
        expect($role)->not->toBe(Role::PlatformAdministrator)
            ->and($role->grants(Capability::AssignRoles))->toBeFalse()
            ->and($role->grants(Capability::ViewResources))->toBeFalse()
            ->and($role->grants(Capability::ManageResources))->toBeFalse();
        foreach (Capability::cases() as $capability) {
            if (str_starts_with($capability->value, 'identity.')) {
                expect($role->grants($capability))->toBeFalse();
            }
        }
    }
});
