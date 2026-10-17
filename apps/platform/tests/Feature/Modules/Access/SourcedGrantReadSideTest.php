<?php

declare(strict_types=1);

use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\GrantSourcedRole;
use App\Modules\Access\Application\LastAdministratorRequired;
use App\Modules\Access\Application\PeopleHoldingCapability;
use App\Modules\Access\Application\ProvisionableRole;
use App\Modules\Access\Application\RevokeRole;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Application\RoleGrantSource;
use App\Modules\Identity\Domain\Account;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Access;
use Tests\Support\Identity;

/*
 * G6 WP2-03. A relationship source may confer only a ProvisionableRole (ADR 0038, K2). The write path already
 * guarantees that; these tests pin the READ side, in both consumers: the Authorizer (what an Actor may do) and
 * PeopleHoldingCapability (who holds a capability). A row planted past the write path, by a manual edit or a
 * mismatched restore, must confer nothing through either, while independent assignments keep the whole catalog.
 */

/** @return list<string> the capability identifiers the Account's Person currently holds */
function sourcedHeld(Account $account): array
{
    return array_map(fn (Capability $capability): string => $capability->value, app(Authorizer::class)->capabilitiesOf(Access::actorFor($account)));
}

/** @return list<string> the Person ids PeopleHoldingCapability reports for the capability, sorted */
function sourcedHolders(Capability $capability): array
{
    $ids = array_map(fn (PersonId $person): string => $person->value, app(PeopleHoldingCapability::class)($capability));
    sort($ids);

    return $ids;
}

it('confers nothing from a sourced platform_administrator row, through either consumer', function () {
    $admin = Access::admin('admin@example.org');
    $victim = Identity::savedActiveAccount('victim@example.org');
    Access::plantSourced($victim->personId, 'platform_administrator');

    expect(sourcedHeld($victim))->toBe([])
        ->and(app(Authorizer::class)->allows(Access::actorFor($victim), Capability::AssignRoles))->toBeFalse();
    foreach (Capability::cases() as $capability) {
        expect(sourcedHolders($capability))->toBe([$admin->personId->value], $capability->value);
    }
});

it('confers nothing from a sourced guardian-full, guardian-senior or console-participant row: real roles, but not provisionable', function (string $roleKey) {
    $holder = Identity::savedActiveAccount('holder@example.org');
    Access::plantSourced($holder->personId, $roleKey);

    expect(sourcedHeld($holder))->toBe([]);
    foreach (Capability::cases() as $capability) {
        expect(sourcedHolders($capability))->toBe([], $capability->value);
    }
})->with(['guardian-full', 'guardian-senior', 'console-participant']);

it('still confers console.access from a valid sourced guardian-initiate grant, and counts the holder', function () {
    $admin = Access::admin('admin@example.org');
    $holder = Identity::savedActiveAccount('holder@example.org');
    app(GrantSourcedRole::class)(Access::actorFor($admin), $holder->personId, ProvisionableRole::GuardianInitiate, RoleGrantSource::relationship(strtolower((string) Str::ulid())));

    expect(sourcedHeld($holder))->toBe(['console.access'])
        ->and(sourcedHolders(Capability::ConsoleAccess))->toBe(sortedIds($admin->personId, $holder->personId))
        ->and(sourcedHolders(Capability::ViewResources))->toBe([$admin->personId->value]);
});

it('confers nothing from unknown, retired or wrongly written sourced keys', function (string $roleKey) {
    $holder = Identity::savedActiveAccount('holder@example.org');
    Access::plantSourced($holder->personId, $roleKey);

    expect(sourcedHeld($holder))->toBe([]);
    foreach ([Capability::ConsoleAccess, Capability::AssignRoles, Capability::ViewResources] as $capability) {
        expect(sourcedHolders($capability))->toBe([], $capability->value);
    }
})->with(['guardian', 'bogus-role', 'Platform_Administrator', 'PLATFORM_ADMINISTRATOR', 'Guardian-Initiate', 'GUARDIAN-INITIATE', 'platform_administrator ', ' guardian-initiate']);

it('ignores an invalid sourced row without touching a valid independent role the Person also holds', function () {
    $holder = Identity::savedActiveAccount('holder@example.org');
    Access::grant($holder, Role::GuardianFull);
    Access::plantSourced($holder->personId, 'platform_administrator');
    Access::plantSourced($holder->personId, 'guardian-senior');

    expect(sourcedHeld($holder))->toBe(sortedValues(Role::GuardianFull->capabilities()))
        ->and(sourcedHolders(Capability::AssignRoles))->toBe([])
        ->and(sourcedHolders(Capability::ViewResources))->toBe([$holder->personId->value])
        ->and(DB::table('sourced_role_grants')->count())->toBe(2); // read-side only: nothing is repaired or removed
});

it('combines an independent role with a valid sourced grant into one capability set and one holder', function () {
    $admin = Access::admin('admin@example.org');
    $holder = Identity::savedActiveAccount('holder@example.org');
    Access::grant($holder, Role::ConsoleParticipant);
    app(GrantSourcedRole::class)(Access::actorFor($admin), $holder->personId, ProvisionableRole::GuardianInitiate, RoleGrantSource::relationship(strtolower((string) Str::ulid())));
    app(GrantSourcedRole::class)(Access::actorFor($admin), $holder->personId, ProvisionableRole::GuardianInitiate, RoleGrantSource::relationship(strtolower((string) Str::ulid())));

    expect(sourcedHeld($holder))->toBe(['console.access'])
        ->and(sourcedHolders(Capability::ConsoleAccess))->toBe(sortedIds($admin->personId, $holder->personId));
});

it('keeps an independent platform_administrator assignment fully authoritative whatever sourced rows sit beside it', function () {
    $admin = Access::admin('admin@example.org');
    Access::plantSourced($admin->personId, 'bogus-role');
    Access::plantSourced($admin->personId, 'guardian-full');

    expect(count(sourcedHeld($admin)))->toBe(count(Capability::cases()))
        ->and(sourcedHolders(Capability::AssignRoles))->toBe([$admin->personId->value]);
});

it('keeps the last-administrator protection on independent assignments: a sourced administrator row is never a second administrator', function () {
    $only = Access::admin('only@example.org');
    $other = Identity::savedActiveAccount('other@example.org');
    Access::plantSourced($other->personId, 'platform_administrator');

    expect(fn () => app(RevokeRole::class)(Access::actorFor($only), $only->personId, Role::PlatformAdministrator))
        ->toThrow(LastAdministratorRequired::class)
        ->and(sourcedHeld($other))->toBe([]);
});

it('agrees between who holds a capability and what the Authorizer lets that Person do, across every kind of row', function () {
    $admin = Access::admin('admin@example.org');
    $people = ['admin' => $admin];
    $sourced = [
        'sourced-admin' => 'platform_administrator', 'sourced-full' => 'guardian-full', 'sourced-senior' => 'guardian-senior',
        'sourced-participant' => 'console-participant', 'sourced-initiate' => 'guardian-initiate',
        'sourced-retired' => 'guardian', 'sourced-bogus' => 'bogus-role', 'sourced-miscased' => 'Guardian-Initiate',
    ];
    foreach ($sourced as $label => $key) {
        $account = Identity::savedActiveAccount($label.'@example.org');
        Access::plantSourced($account->personId, $key);
        $people[$label] = $account;
    }
    $both = Identity::savedActiveAccount('both@example.org');
    Access::grant($both, Role::GuardianSenior);
    Access::plantSourced($both->personId, 'platform_administrator');
    $people['both'] = $both;

    foreach (Capability::cases() as $capability) {
        $allowed = [];
        foreach ($people as $account) {
            if (app(Authorizer::class)->allows(Access::actorFor($account), $capability)) {
                $allowed[] = $account->personId->value;
            }
        }
        sort($allowed);

        expect(sourcedHolders($capability))->toBe($allowed, $capability->value);
    }
});

/** @return list<string> */
function sortedIds(PersonId ...$people): array
{
    $ids = array_map(fn (PersonId $person): string => $person->value, $people);
    sort($ids);

    return $ids;
}

/**
 * @param  list<Capability>  $capabilities
 * @return list<string>
 */
function sortedValues(array $capabilities): array
{
    $values = array_map(fn (Capability $capability): string => $capability->value, $capabilities);
    sort($values);

    return $values;
}
