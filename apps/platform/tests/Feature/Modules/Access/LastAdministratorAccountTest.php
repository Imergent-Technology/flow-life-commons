<?php

declare(strict_types=1);

use App\Modules\Access\Application\LastAdministratorDeactivationGuard;
use App\Modules\Access\Application\LastAdministratorRequired;
use App\Modules\Access\Application\RevokeRole;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\AccountDeactivationRefused;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Identity;

/*
 * The last-administrator invariant on the ACCOUNT path (ADR 0020): the same authority as the role
 * path (AdministratorContinuity), reached through the deactivation guard Identity consults. The
 * race between the two is in tests/Concurrency.
 */

function disable(Account $account): void
{
    app(DisableAccount::class)($account->id);
}

function stillActive(Account $account): bool
{
    return app(AccountRepository::class)->find($account->id)?->status === AccountStatus::Active;
}

it('refuses to disable the Account of the last active administrator', function () {
    $only = Access::admin('only@example.org');
    $console = new Console;
    $console->login('only@example.org', Identity::PASSWORD)->assertOk();

    expect(fn () => disable($only))->toThrow(AccountDeactivationRefused::class, 'The platform must keep at least one active administrator.');

    expect(stillActive($only))->toBeTrue()
        ->and(Access::activeAdministrators())->toBe(1)
        ->and(Identity::events('account.disabled'))->toBe([]);  // refused: nothing changed, nothing recorded
    $console->me()->assertOk();                                  // and they are still signed in
});

it('lets an administrator Account be disabled while another viable administrator remains', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $bob = Access::admin('bob@example.org', 'Bob');

    disable($ada);

    expect(stillActive($ada))->toBeFalse()
        ->and(stillActive($bob))->toBeTrue()
        ->and(Access::activeAdministrators())->toBe(1)
        // Disabling does not remove the assignment: the person is still recorded as an administrator.
        ->and(DB::table('role_assignments')->where('person_id', $ada->personId->value)->count())->toBe(1);
});

it('does not count an INVITED administrator as active', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $invited = Identity::savedInvitedAccount('invited@example.org');
    Access::grant($invited, Role::PlatformAdministrator);

    expect(fn () => disable($ada))->toThrow(AccountDeactivationRefused::class)
        ->and(stillActive($ada))->toBeTrue();
});

it('does not count a DISABLED administrator as active', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $bob = Access::admin('bob@example.org', 'Bob');
    disable($bob);

    expect(fn () => disable($ada))->toThrow(AccountDeactivationRefused::class)
        ->and(stillActive($ada))->toBeTrue();
});

it('does not count an ordinary guardian as an administrator', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);

    expect(fn () => disable($ada))->toThrow(AccountDeactivationRefused::class);
});

it('does not count a wrongly-cased role key as an administrator, on either engine', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $impostor = Identity::savedActiveAccount('impostor@example.org', name: 'Impostor');
    Access::plant($impostor->personId, 'PLATFORM_ADMINISTRATOR');

    expect(fn () => disable($ada))->toThrow(AccountDeactivationRefused::class);
});

it('never blocks disabling an account that is not an administrator, even when the only administrator exists', function () {
    Access::admin('admin@example.org');
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);
    $nobody = Identity::savedActiveAccount('nobody@example.org', name: 'Nobody');

    disable($guardian);
    disable($nobody);

    expect(Access::activeAdministrators())->toBe(1);
});

it('lets a non-viable administrator Account be disabled, since that removes no active authority', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $invited = Identity::savedInvitedAccount('invited@example.org');
    Access::grant($invited, Role::PlatformAdministrator);

    disable($invited);

    expect(stillActive($ada))->toBeTrue()->and(Access::activeAdministrators())->toBe(1);
});

it('protects the invariant whichever way authority is removed: revoke first, then disable', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $bob = Access::admin('bob@example.org', 'Bob');

    app(RevokeRole::class)(Access::actorFor($ada), $bob->personId, Role::PlatformAdministrator);

    // Bob is no longer an administrator, so Ada is the last one and cannot be disabled.
    expect(fn () => disable($ada))->toThrow(AccountDeactivationRefused::class);
});

it('protects the invariant whichever way authority is removed: disable first, then revoke', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    $bob = Access::admin('bob@example.org', 'Bob');
    disable($ada);

    expect(fn () => app(RevokeRole::class)(Access::actorFor($bob), $bob->personId, Role::PlatformAdministrator))
        ->toThrow(LastAdministratorRequired::class);
});

// --- One authority, one lock path ----------------------------------------------------------------------

it('locks the administrator assignments before it touches any account, when disabling', function () {
    $ada = Access::admin('ada@example.org', 'Ada');
    Access::admin('bob@example.org', 'Bob');
    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $log[] = strtolower($query->sql);
    });

    disable($ada);

    $inside = array_slice($log, (int) array_search('BEGIN', $log, true) + 1);
    $touchesAssignments = fn (string $s): bool => str_contains($s, 'role_assignments');
    $touchesAccounts = fn (string $s): bool => str_contains($s, 'accounts') && str_starts_with($s, 'select');
    $firstAssignments = array_find_key($inside, $touchesAssignments);
    $firstAccounts = array_find_key($inside, $touchesAccounts);

    expect($firstAssignments)->not->toBeNull()->and($firstAccounts)->not->toBeNull()
        ->and($inside[(int) $firstAssignments])->toContain('for update')->toContain('order by')
        ->and($inside[(int) $firstAccounts])->toContain('for update')
        ->and((int) $firstAssignments)->toBeLessThan((int) $firstAccounts);
});

it('has exactly one implementation of the invariant, reached by both removal paths', function () {
    // Both paths ask AdministratorContinuity, and only it takes the administrator lock.
    $callers = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)) as $file) {
        assert($file instanceof SplFileInfo);
        $source = $file->getExtension() === 'php' ? (string) file_get_contents($file->getPathname()) : '';
        if (str_contains($source, 'lockHoldersOf(') && ! str_contains($file->getFilename(), 'RoleAssignmentRepository')) {
            $callers[] = $file->getFilename();
        }
        if (str_contains($source, 'assertMayRemoveAdministratorAuthorityOf(') && ! str_contains($file->getFilename(), 'AdministratorContinuity')) {
            $callers[] = $file->getFilename().' (guard)';
        }
    }
    sort($callers);

    expect($callers)->toBe(['AdministratorContinuity.php', 'LastAdministratorDeactivationGuard.php (guard)', 'RevokeRole.php (guard)']);
});

it('is the guard Identity consults, so a disable cannot bypass it', function () {
    $ada = Access::admin('ada@example.org', 'Ada');

    expect(app(LastAdministratorDeactivationGuard::class))->toBeInstanceOf(LastAdministratorDeactivationGuard::class);
    expect(fn () => disable($ada))->toThrow(AccountDeactivationRefused::class);
});
