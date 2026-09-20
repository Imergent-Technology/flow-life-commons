<?php

declare(strict_types=1);

use App\Modules\Access\Application\LastAdministratorRequired;
use App\Modules\Access\Application\RevokeRole;
use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\AccountDeactivationRefused;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Race;

/*
 * The last-administrator invariant under REAL concurrency (ADR 0020), across two PHP processes
 * and two database connections.
 *
 * Method: see Tests\Support\Race. The first operation pauses inside its open transaction and a
 * second PHP process runs a competing removal.
 *
 * With the serialization in place the worker BLOCKS on the administrator lock until the first
 * transaction commits, then re-reads the committed state, sees it is now the last administrator,
 * and is refused. Without it the worker sails through on stale data and both removals succeed.
 * Every scenario asserts both halves (it blocked, and the platform still has an active
 * administrator), because either alone can pass for the wrong reason.
 *
 * Runs on MariaDB and PostgreSQL: `./flow test backend` and `./flow test backend --pgsql`.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
});

afterEach(function () {
    Race::clean();
});

/** @return array{Account, Account} two ACTIVE administrators, committed */
function twoAdministrators(): array
{
    return [Access::admin('ada@example.org', 'Ada'), Access::admin('bob@example.org', 'Bob')];
}

it('serialises a revoke against a disable: the second is refused, and an administrator survives', function () {
    [$ada, $bob] = twoAdministrators();

    $race = Race::against(
        fn (Closure $pause) => app(RevokeRole::class)(Access::actorFor($ada), $bob->personId, Role::PlatformAdministrator),
        'role.revoked', 'disable', ['account' => $ada->id->value],
    );

    expect($race['blocked'])->toBeTrue('the competing disable did not wait for the revoke to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(AccountDeactivationRefused::class)
        ->and(Access::activeAdministrators())->toBe(1);
});

it('serialises a disable against a revoke: the second is refused, and an administrator survives', function () {
    [$ada, $bob] = twoAdministrators();

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($ada->id),
        'account.disabled', 'revoke', ['actor_account' => $ada->id->value, 'actor_person' => $ada->personId->value, 'person' => $bob->personId->value],
    );

    expect($race['blocked'])->toBeTrue('the competing revoke did not wait for the disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(LastAdministratorRequired::class)
        ->and(Access::activeAdministrators())->toBe(1);
});

it('serialises two revokes: the second is refused, and an administrator survives', function () {
    [$ada, $bob] = twoAdministrators();

    $race = Race::against(
        fn (Closure $pause) => app(RevokeRole::class)(Access::actorFor($ada), $ada->personId, Role::PlatformAdministrator),
        'role.revoked', 'revoke', ['actor_account' => $bob->id->value, 'actor_person' => $bob->personId->value, 'person' => $bob->personId->value],
    );

    expect($race['blocked'])->toBeTrue('the competing revoke did not wait')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(LastAdministratorRequired::class)
        ->and(Access::activeAdministrators())->toBe(1);
});

it('serialises two disables: the second is refused, and an administrator survives', function () {
    [$ada, $bob] = twoAdministrators();

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($ada->id),
        'account.disabled', 'disable', ['account' => $bob->id->value],
    );

    expect($race['blocked'])->toBeTrue('the competing disable did not wait')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(AccountDeactivationRefused::class)
        ->and(Access::activeAdministrators())->toBe(1);
});

it('still serialises, but does not over-refuse, when a third administrator makes the removal safe', function () {
    // The control: the lock makes the second operation WAIT, and it then succeeds because,
    // on the committed state, an active administrator really does remain.
    [$ada, $bob] = twoAdministrators();
    $cleo = Access::admin('cleo@example.org', 'Cleo');

    $race = Race::against(
        fn (Closure $pause) => app(RevokeRole::class)(Access::actorFor($ada), $bob->personId, Role::PlatformAdministrator),
        'role.revoked', 'disable', ['account' => $ada->id->value],
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exit'])->toBe(0)
        ->and(Access::activeAdministrators())->toBe(1)
        ->and(app(AccountRepository::class)->find($cleo->id)?->canAuthenticate())->toBeTrue();
});

it('holds even against an account change that bypasses the guard, because the survivors\' accounts are locked too', function () {
    // Defence in depth. A future path that took an administrator out of service WITHOUT going
    // through the deactivation guard (it forgot, or it is a bug) never takes the assignments lock.
    // The survivors' account-row locks are what still make a concurrent revoke wait for it and
    // then see it, instead of counting an administrator that is about to stop being active.
    [$ada, $bob] = twoAdministrators();

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($ada, $pause): void {
            app(AccountRepository::class)->save($ada->disable(Identity::now()->modify('+1 day'))); // no guard
            $pause();
        }),
        null, 'revoke', ['actor_account' => $ada->id->value, 'actor_person' => $ada->personId->value, 'person' => $bob->personId->value],
    );

    expect($race['blocked'])->toBeTrue('the revoke did not wait for the unguarded disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(LastAdministratorRequired::class)
        ->and(Access::activeAdministrators())->toBe(1);
});

it('does not let a sign-in that raced a disable bring the account back', function () {
    // The Phase 2 defect, across two real processes: login has read the account and is about to
    // record the sign-in while a disable is uncommitted. It must wait for it, see it, and fail.
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($target->id),
        'account.disabled', 'login', ['email' => 'target@example.org', 'password' => Identity::PASSWORD],
    );

    expect($race['blocked'])->toBeTrue('the sign-in did not wait for the disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('accounts')->where('id', $target->id->value)->value('status'))->toBe('disabled')
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
});
