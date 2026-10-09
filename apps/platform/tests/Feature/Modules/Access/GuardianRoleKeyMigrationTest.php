<?php

declare(strict_types=1);

use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Infrastructure\RenameGuardianRoleAssignments;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;

/*
 * ADR 0038, M6. The migration renames a permission bundle. It does not create a relationship
 * and it does not change who holds a role. The release that runs it is restore-required:
 * down() is exercised here only as the development reversal.
 */

it('renames guardian to guardian-full and leaves every other assignment and the capability set', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org');
    $admin = Identity::savedActiveAccount('admin@example.org');
    Access::plant($guardian->personId, 'guardian');
    Access::grant($admin, Role::PlatformAdministrator);
    $before = DB::table('role_assignments')->count();
    $events = DB::table('security_events')->count();

    expect(app(Authorizer::class)->capabilitiesOf(Access::actorFor($guardian)))->toBe([]);

    (new RenameGuardianRoleAssignments)->up(DB::connection());

    $full = array_map(fn (Capability $capability): string => $capability->value, Role::GuardianFull->capabilities());
    sort($full, SORT_STRING);
    $every = array_map(fn (Capability $capability): string => $capability->value, Capability::cases());
    sort($every, SORT_STRING);

    expect(DB::table('role_assignments')->where('role_key', 'guardian')->count())->toBe(0)
        ->and(DB::table('role_assignments')->where('person_id', $guardian->personId->value)->value('role_key'))->toBe('guardian-full')
        ->and(DB::table('role_assignments')->where('role_key', 'platform_administrator')->count())->toBe(1)
        ->and(DB::table('role_assignments')->count())->toBe($before)
        ->and(DB::table('person_relationships')->count())->toBe(0)
        ->and(DB::table('security_events')->count())->toBe($events)
        ->and(array_map(fn (Capability $capability): string => $capability->value, app(Authorizer::class)->capabilitiesOf(Access::actorFor($guardian))))->toBe($full)
        ->and(array_map(fn (Capability $capability): string => $capability->value, app(Authorizer::class)->capabilitiesOf(Access::actorFor($admin))))->toBe($every);
});

it('refuses to rename when a guardian-full assignment already exists, and changes nothing', function () {
    $person = Identity::savedActiveAccount('both@example.org');
    Access::grant($person, Role::GuardianFull);
    Access::plant(Identity::savedActiveAccount('old@example.org')->personId, 'guardian');

    expect(fn () => (new RenameGuardianRoleAssignments)->up(DB::connection()))
        ->toThrow(RuntimeException::class, 'guardian-full assignment already exists');

    expect(DB::table('role_assignments')->where('role_key', 'guardian')->count())->toBe(1)
        ->and(DB::table('role_assignments')->where('role_key', 'guardian-full')->count())->toBe(1);
});

it('reverses the rename in development and refuses when a guardian row is already there', function () {
    $person = Identity::savedActiveAccount('renamed@example.org');
    Access::plant($person->personId, 'guardian');
    (new RenameGuardianRoleAssignments)->up(DB::connection());

    (new RenameGuardianRoleAssignments)->down(DB::connection());

    expect(DB::table('role_assignments')->where('person_id', $person->personId->value)->value('role_key'))->toBe('guardian')
        ->and(app(Authorizer::class)->capabilitiesOf(Access::actorFor($person)))->toBe([]);

    expect(fn () => (new RenameGuardianRoleAssignments)->down(DB::connection()))
        ->toThrow(RuntimeException::class, 'a guardian assignment already exists');

    expect(DB::table('role_assignments')->where('person_id', $person->personId->value)->value('role_key'))->toBe('guardian');
});
