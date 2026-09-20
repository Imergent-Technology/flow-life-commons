<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\CompleteSecondFactor;
use App\Modules\Identity\Application\CredentialMarker;
use App\Modules\Identity\Application\PendingLogin;
use App\Modules\Identity\Application\ResetMultiFactor;
use App\Modules\Identity\Application\SecondFactorNeed;
use App\Modules\Identity\Application\SecondFactorProof;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Race;
use Tests\Support\Totp;

/*
 * Recovering a second factor under REAL concurrency (method: Tests\Support\Race), ADR 0024. Everything that checks
 * a second factor locks the Account row first, so a reset that is in flight makes each of them WAIT, and each then
 * decides on what the reset committed: a factor that no longer exists. What must never happen is a sign-in, a
 * step-up proof or a replacement that succeeds on the strength of a factor a committed reset has removed.
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

/**
 * A committed Console Account with an authenticator, and its half-finished sign-in.
 *
 * @return array{Account, PendingLogin, array{secret: string, codes: list<string>}}
 */
function committedFactor(): array
{
    $account = Identity::savedActiveAccount('target@example.org');
    Access::grant($account, Role::Guardian);
    $factor = Mfa::enroll($account);
    $current = app(AccountRepository::class)->find($account->id);
    assert($current !== null);

    return [$account, new PendingLogin($account->id, app(CredentialMarker::class)->for($current), SecondFactorNeed::Challenge), $factor];
}

it('does not let a challenge complete on a factor that a reset committed while it waited', function () {
    [$account, $pending, $factor] = committedFactor();

    $race = Race::against(
        fn (Closure $pause) => app(ResetMultiFactor::class)->fromServer($account->id),
        'mfa.reset_from_server', 'mfa_complete',
        ['account' => $account->id->value, 'marker' => $pending->credentialMarker, 'need' => 'challenge', 'code' => Totp::code($factor['secret'])],
    );

    expect($race['blocked'])->toBeTrue('the challenge did not wait for the reset to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('accounts')->whereNotNull('last_login_at')->count())->toBe(0)
        ->and(Identity::events('authentication.succeeded'))->toBe([])
        ->and(Mfa::factorRow($account))->toBeNull()
        ->and(Identity::context(Identity::events('mfa.challenge_failed')[0]))->toBe(['reason' => 'invalid_code', 'during' => 'sign_in']);
});

it('does not let a RECOVERY CODE be spent for a sign-in that a reset overtook', function () {
    [$account, $pending, $factor] = committedFactor();

    $race = Race::against(
        fn (Closure $pause) => app(ResetMultiFactor::class)->fromServer($account->id),
        'mfa.reset_from_server', 'mfa_complete',
        ['account' => $account->id->value, 'marker' => $pending->credentialMarker, 'need' => 'challenge', 'recovery_code' => $factor['codes'][0]],
    );

    expect($race['blocked'])->toBeTrue('the recovery-code sign-in did not wait for the reset to commit')
        ->and($race['exit'])->toBe(2)
        ->and(Identity::events('authentication.succeeded'))->toBe([])
        ->and(Identity::events('mfa.recovery_code_used'))->toBe([])
        ->and(DB::table('account_recovery_codes')->count())->toBe(0);
});

it('does not let a step-up proof succeed on a factor a reset committed while it waited', function () {
    [$account, , $factor] = committedFactor();

    $race = Race::against(
        fn (Closure $pause) => app(ResetMultiFactor::class)->fromServer($account->id),
        'mfa.reset_from_server', 'security_verify',
        ['account' => $account->id->value, 'person' => $account->personId->value, 'password' => Identity::PASSWORD, 'code' => Totp::code($factor['secret'])],
    );

    expect($race['blocked'])->toBeTrue('the step-up did not wait for the reset to commit')
        ->and($race['exit'])->toBe(2)
        ->and(Identity::events('security.reverified'))->toBe([]);
});

it('does not let an authenticator REPLACEMENT begin on a factor a reset committed while it waited', function () {
    [$account, , $factor] = committedFactor();

    $race = Race::against(
        fn (Closure $pause) => app(ResetMultiFactor::class)->fromServer($account->id),
        'mfa.reset_from_server', 'replace_begin',
        ['account' => $account->id->value, 'person' => $account->personId->value, 'password' => Identity::PASSWORD, 'code' => Totp::code($factor['secret'])],
    );

    // Nothing is left behind: no pending secret survives a reset that beat the replacement.
    expect($race['blocked'])->toBeTrue('the replacement did not wait for the reset to commit')
        ->and($race['exit'])->toBe(2)
        ->and(Mfa::factorRow($account))->toBeNull();
});

it('makes a reset wait for a sign-in already in flight, and then remove what it committed', function () {
    // The reverse order: the challenge holds the Account lock and has not committed. The reset waits, then removes
    // the factor. The sign-in that committed first stays committed (its session is the transport's, after commit:
    // the residual window ADR 0024 states); what matters here is that the reset is serialised behind it.
    [$account, $pending, $factor] = committedFactor();

    $race = Race::against(
        fn (Closure $pause) => app(CompleteSecondFactor::class)($pending, SecondFactorProof::totp(Totp::code($factor['secret'])), new ClientContext('127.0.0.1', 'first')),
        'authentication.succeeded', 'mfa_reset', ['account' => $account->id->value],
    );

    expect($race['blocked'])->toBeTrue('the reset did not wait for the sign-in to commit')
        ->and($race['exit'])->toBe(0)
        ->and(Identity::events('authentication.succeeded'))->toHaveCount(1)
        ->and(Mfa::factorRow($account))->toBeNull()
        ->and(Identity::events('mfa.reset_from_server'))->toHaveCount(1);
});
