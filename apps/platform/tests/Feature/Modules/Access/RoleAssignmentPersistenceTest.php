<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Access\Domain\RoleAlreadyAssigned;
use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Access;
use Tests\Support\Identity;

function assignments(): RoleAssignmentRepository
{
    return app(RoleAssignmentRepository::class);
}

it('persists a role key as a plain string and reads it back unchanged', function () {
    $account = Identity::savedActiveAccount();
    $by = AccountId::generate();

    $granted = Access::grant($account, Role::PlatformAdministrator, $by);

    $found = assignments()->forPerson($account->personId);
    expect($found)->toHaveCount(1)
        ->and($found[0]->id)->toEqual($granted->id)
        ->and($found[0]->roleKey)->toBe('platform_administrator')
        ->and($found[0]->grantedByAccountId)->toEqual($by)
        ->and($found[0]->grantedAt)->toEqual(Identity::now())
        ->and(DB::table('role_assignments')->value('role_key'))->toBe('platform_administrator');
});

it('stores the grant time in UTC', function () {
    $account = Identity::savedActiveAccount();
    $local = new DateTimeImmutable('2026-09-19 12:00:00', new DateTimeZone('+10:00')); // 02:00 UTC

    assignments()->add(RoleAssignment::grant($account->personId, 'guardian', null, $local));

    expect(DB::table('role_assignments')->value('granted_at'))->toBe('2026-09-19 02:00:00')
        ->and(assignments()->forPerson($account->personId)[0]->grantedAt->getTimestamp())->toBe($local->getTimestamp());
});

it('allows a grant with no granting account, as the platform itself makes', function () {
    $account = Identity::savedActiveAccount();

    Access::grant($account, Role::PlatformAdministrator);

    expect(assignments()->forPerson($account->personId)[0]->grantedByAccountId)->toBeNull();
});

it('returns only the requested person\'s assignments, oldest first', function () {
    $ada = Identity::savedActiveAccount('ada@example.org');
    $bob = Identity::savedActiveAccount('bob@example.org', name: 'Bob');
    assignments()->add(RoleAssignment::grant($ada->personId, 'guardian', null, Identity::now()->modify('+2 hours')));
    assignments()->add(RoleAssignment::grant($ada->personId, 'platform_administrator', null, Identity::now()));
    Access::grant($bob, Role::Guardian);

    expect(array_map(fn (RoleAssignment $a): string => $a->roleKey, assignments()->forPerson($ada->personId)))
        ->toBe(['platform_administrator', 'guardian'])
        ->and(assignments()->forPerson(PersonId::generate()))->toBe([]);
});

it('reads fresh on every call, so a change is visible to the very next read', function () {
    $account = Identity::savedActiveAccount();
    expect(assignments()->forPerson($account->personId))->toBe([]);

    Access::grant($account, Role::Guardian);
    expect(assignments()->forPerson($account->personId))->toHaveCount(1);

    Access::revoke($account, Role::Guardian);
    expect(assignments()->forPerson($account->personId))->toBe([]);
});

// --- Uniqueness ------------------------------------------------------------------------

it('holds at most one grant per person and role', function () {
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::Guardian);

    $error = Identity::violation(fn () => Access::grant($account, Role::Guardian));

    expect($error)->toBeInstanceOf(RoleAlreadyAssigned::class)
        ->and(DB::table('role_assignments')->count())->toBe(1);
});

it('holds that uniqueness in the database itself, not only in PHP', function () {
    $account = Identity::savedActiveAccount();
    Access::plant($account->personId, 'guardian');

    $error = Identity::violation(fn () => Access::plant($account->personId, 'guardian'));

    expect($error)->toBeInstanceOf(UniqueConstraintViolationException::class);
});

it('lets one person hold several roles, and several people hold one role', function () {
    $ada = Identity::savedActiveAccount('ada@example.org');
    $bob = Identity::savedActiveAccount('bob@example.org', name: 'Bob');

    Access::grant($ada, Role::Guardian);
    Access::grant($ada, Role::PlatformAdministrator);
    Access::grant($bob, Role::Guardian);

    expect(DB::table('role_assignments')->count())->toBe(3);
});

// --- Referential integrity (ADR 0021) ----------------------------------------------------

it('refuses a grant to a person who does not exist', function () {
    $error = Identity::violation(fn () => Access::grant(PersonId::generate(), Role::Guardian));

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and(DB::table('role_assignments')->count())->toBe(0);
});

it('refuses to delete a person who still holds a grant (RESTRICT, not CASCADE)', function () {
    $account = Identity::savedActiveAccount();
    DB::table('accounts')->where('id', $account->id->value)->delete(); // the Account is not the blocker
    Access::grant($account->personId, Role::PlatformAdministrator);

    $error = Identity::violation(fn () => DB::table('people')->where('id', $account->personId->value)->delete());

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and(DB::table('people')->where('id', $account->personId->value)->exists())->toBeTrue()
        ->and(DB::table('role_assignments')->count())->toBe(1);
});

it('allows the person to be removed once the grant is explicitly revoked', function () {
    $account = Identity::savedActiveAccount();
    DB::table('accounts')->where('id', $account->id->value)->delete();
    Access::grant($account->personId, Role::Guardian);

    Access::revoke($account->personId, Role::Guardian);
    DB::table('people')->where('id', $account->personId->value)->delete();

    expect(DB::table('people')->count())->toBe(0);
});

it('keeps granted_by_account_id as provenance with no foreign key', function () {
    $account = Identity::savedActiveAccount();
    $vanished = AccountId::generate(); // no such account

    Access::grant($account, Role::Guardian, $vanished);

    expect(assignments()->forPerson($account->personId)[0]->grantedByAccountId)->toEqual($vanished);
});

it('references only Identity\'s people table, and only through the person foreign key', function () {
    $targets = [];
    foreach (Schema::getForeignKeys('role_assignments') as $foreignKey) {
        assert(is_array($foreignKey));
        assert(is_string($foreignKey['foreign_table']) && is_array($foreignKey['columns']));
        $columns = array_map(fn (mixed $c): string => is_string($c) ? $c : '', $foreignKey['columns']);
        $targets[] = $foreignKey['foreign_table'].'('.implode(',', $columns).')';
    }

    expect($targets)->toBe(['people(person_id)']);
});

// --- Shape -----------------------------------------------------------------------------

it('has only the frozen columns: no revoked_at, soft delete, scope or history', function () {
    $columns = array_map(fn (mixed $c): string => is_array($c) && is_string($c['name']) ? $c['name'] : '', Schema::getColumns('role_assignments'));

    expect($columns)->toEqualCanonicalizing(['id', 'person_id', 'role_key', 'granted_by_account_id', 'granted_at']);
});

it('has no roles table and no role-capabilities table: roles and capabilities are code', function () {
    expect(Schema::hasTable('roles'))->toBeFalse()
        ->and(Schema::hasTable('role_capabilities'))->toBeFalse()
        ->and(Schema::hasTable('permissions'))->toBeFalse();
});

it('stores the role key as a VARCHAR, never a database ENUM', function () {
    $type = '';
    foreach (Schema::getColumns('role_assignments') as $column) {
        assert(is_array($column));
        if ($column['name'] === 'role_key') {
            assert(is_string($column['type']));
            $type = $column['type'];
        }
    }

    expect($type)->toContain('64')->and($type)->not->toContain('enum');
});

it('reads a corrupt or obsolete stored key back exactly as stored, without failing', function (string $key) {
    $account = Identity::savedActiveAccount();
    Access::plant($account->personId, $key);

    expect(assignments()->forPerson($account->personId)[0]->roleKey)->toBe($key);
})->with(['retired_role', 'PLATFORM_ADMINISTRATOR', 'guardian ', '', 'is_admin']);
