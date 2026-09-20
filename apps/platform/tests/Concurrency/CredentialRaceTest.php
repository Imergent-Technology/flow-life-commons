<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\AccountRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Identity;
use Tests\Support\Race;

/*
 * Credential changes under REAL concurrency, across two PHP processes and two database
 * connections (method: Tests\Support\Race).
 *
 * The rule under test is the Phase 4 lesson applied to credentials: an Account read before a
 * security-sensitive race window must never be saved back or acted on as if it were still current.
 * Whatever committed while a flow waited on the Account's row lock has to be seen after it.
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

it('does not let a sign-in that verified the old password succeed once that password has been replaced', function () {
    // The sign-in reads the Account and checks the password against the hash it saw, then waits
    // for the row lock while a change to the credential is still uncommitted. When the lock is
    // released the stored hash is different: what it proved is a password that no longer exists.
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($target, $pause): void {
            app(AccountRepository::class)->save(Identity::withPasswordHash($target, Hash::make('a brand new passphrase')));
            $pause();
        }),
        null, 'login', ['email' => 'target@example.org', 'password' => Identity::PASSWORD],
    );

    expect($race['blocked'])->toBeTrue('the sign-in did not wait for the credential change to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0)
        ->and(DB::table('accounts')->whereNotNull('last_login_at')->count())->toBe(0);
});

it('still signs in, after waiting, when the concurrent change left the credential alone', function () {
    // The control: the lock makes the sign-in WAIT, and it succeeds because the stored hash is
    // still the one it verified. Without it the test above could pass by refusing everything.
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($target, $pause): void {
            $current = app(AccountRepository::class)->findForUpdate($target->id);
            assert($current !== null);
            app(AccountRepository::class)->save($current); // an unrelated write to the same row
            $pause();
        }),
        null, 'login', ['email' => 'target@example.org', 'password' => Identity::PASSWORD],
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('accounts')->whereNotNull('last_login_at')->count())->toBe(1);
});
