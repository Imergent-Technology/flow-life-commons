<?php

declare(strict_types=1);

use App\Modules\Access\Application\AdministratorContinuity;
use App\Modules\Access\Application\LastAdministratorRequired;
use App\Modules\Access\Application\RevokeRole;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Identity\Application\ActiveAccountQuery;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Shared\Domain\PersonId;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Support\Access;
use Tests\Support\Identity;

/*
 * The last-administrator invariant on the ROLE path (ADR 0020). The Account path shares the
 * same authority and is tested with account deactivation; the race between the two is in
 * tests/Concurrency.
 *
 * "Active administrator" means holds platform_administrator AND the Account can still
 * authenticate. Counting assignment rows would be wrong.
 */

function revokeAdministrator(Account $by, Account $from): void
{
    app(RevokeRole::class)(Access::actorFor($by), $from->personId, Role::PlatformAdministrator);
}

it('refuses to revoke the last active administrator', function () {
    $only = Access::admin('only@example.org');

    expect(fn () => revokeAdministrator($only, $only))->toThrow(LastAdministratorRequired::class);

    expect(Access::activeAdministrators())->toBe(1)
        ->and(DB::table('role_assignments')->where('role_key', 'platform_administrator')->count())->toBe(1)
        ->and(Identity::events('role.revoked'))->toBe([]); // refused, so nothing changed and nothing is recorded
});

it('lets an administrator be revoked while another viable administrator remains', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $bob = Access::admin('bob@example.org', 'Bob');

    revokeAdministrator($ada, $bob);

    expect(Access::activeAdministrators())->toBe(1)
        ->and(Identity::events('role.revoked'))->toHaveCount(1);
});

it('lets an administrator revoke their own role while another viable administrator remains', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    Access::admin('bob@example.org', 'Bob');

    revokeAdministrator($ada, $ada);

    expect(Access::activeAdministrators())->toBe(1);
});

it('does not count an INVITED administrator as active', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $invited = Identity::savedInvitedAccount('invited@example.org');
    Access::grant($invited, Role::PlatformAdministrator);

    expect(Access::activeAdministrators())->toBe(1)
        ->and(fn () => revokeAdministrator($ada, $ada))->toThrow(LastAdministratorRequired::class);
});

it('does not count a DISABLED administrator as active', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $bob = Access::admin('bob@example.org', 'Bob');
    app(AccountRepository::class)->save($bob->disable(Identity::now()->modify('+1 day')));

    expect(Access::activeAdministrators())->toBe(1)
        ->and(fn () => revokeAdministrator($ada, $ada))->toThrow(LastAdministratorRequired::class);
});

it('does not count an administrator who has no account at all', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $contact = Identity::savedPerson('No Account');
    Access::grant($contact->id, Role::PlatformAdministrator);

    expect(Access::activeAdministrators())->toBe(1)
        ->and(fn () => revokeAdministrator($ada, $ada))->toThrow(LastAdministratorRequired::class);
});

it('does not count an ordinary guardian as an administrator', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);

    expect(fn () => revokeAdministrator($ada, $ada))->toThrow(LastAdministratorRequired::class);
});

it('does not count a wrongly-cased role key as an administrator, on either engine', function () {
    // MariaDB compares VARCHAR case-insensitively and PostgreSQL does not; the catalog matches
    // exactly and grants nothing on such a row, so it must not make anyone "another administrator".
    $ada = Access::admin('ada@example.org', 'Ada');
    $impostor = Identity::savedActiveAccount('impostor@example.org', name: 'Impostor');
    Access::plant($impostor->personId, 'PLATFORM_ADMINISTRATOR');

    expect(app(RoleAssignmentRepository::class)->holdersOf('platform_administrator'))->toHaveCount(1)
        ->and(fn () => revokeAdministrator($ada, $ada))->toThrow(LastAdministratorRequired::class);
});

it('lets a non-viable administrator be revoked, since that removes no active authority', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $invited = Identity::savedInvitedAccount('invited@example.org');
    Access::grant($invited, Role::PlatformAdministrator);

    revokeAdministrator($ada, $invited);

    expect(Access::activeAdministrators())->toBe(1)
        ->and(DB::table('role_assignments')->where('person_id', $invited->personId->value)->count())->toBe(0);
});

it('does not apply the guard to other roles', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    Access::grant($ada, Role::Guardian);

    app(RevokeRole::class)(Access::actorFor($ada), $ada->personId, Role::Guardian);

    expect(Access::activeAdministrators())->toBe(1);
});

// --- Lock before decide (ADR 0020), proven from the statements actually issued -----------------------------

/**
 * @return list<string> the SQL issued after the transaction began, lower-cased
 */
function statementsAfterBegin(Closure $work): array
{
    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $log[] = strtolower($query->sql);
    });
    $work();

    $began = array_search('BEGIN', $log, true);
    assert(is_int($began));

    return array_slice($log, $began + 1);
}

it('takes the administrator locks before it reads anything to decide, and locks in a fixed order', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $bob = Access::admin('bob@example.org', 'Bob');

    $statements = statementsAfterBegin(fn () => revokeAdministrator($ada, $bob));

    $touching = fn (string $table): array => array_values(array_filter($statements, fn (string $s): bool => str_starts_with($s, 'select') && str_contains($s, $table)));

    // Every deciding read of these tables inside the transaction is a LOCKING read...
    foreach (['role_assignments', 'accounts'] as $table) {
        expect($touching($table))->not->toBe([], "no read of {$table}");
        foreach ($touching($table) as $sql) {
            expect($sql)->toContain('for update');
        }
    }
    // ...ordered by id, so every transaction takes its locks in the same order...
    foreach (['role_assignments', 'accounts'] as $table) {
        foreach ($touching($table) as $sql) {
            expect($sql)->toContain('order by');
        }
    }
    // ...the assignments are locked before the accounts, and both before the delete.
    $firstAt = fn (string $needle): int => (int) array_search(true, array_map(fn (string $s): bool => str_contains($s, $needle), $statements), true);
    $assignmentLock = $firstAt('from "role_assignments"') ?: $firstAt('from `role_assignments`');
    $accountLock = $firstAt('from "accounts"') ?: $firstAt('from `accounts`');
    $delete = $firstAt('delete from');
    expect($assignmentLock)->toBeLessThan($accountLock)->and($accountLock)->toBeLessThan($delete);
});

it('refuses to run its check outside a transaction, where a lock would be released at once', function () {
    /** @var Connection&MockInterface $connection */
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('transactionLevel')->andReturn(0);
    $continuity = new AdministratorContinuity(
        app(RoleAssignmentRepository::class), app(ActiveAccountQuery::class), $connection,
    );

    expect(fn () => $continuity->assertMayRemoveAdministratorAuthorityOf(PersonId::generate()))->toThrow(LogicException::class);
});
