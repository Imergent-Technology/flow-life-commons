<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\BeginTotpEnrollment;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\CompleteSecondFactor;
use App\Modules\Identity\Application\CredentialMarker;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\PendingLogin;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Application\SecondFactorNeed;
use App\Modules\Identity\Application\SecondFactorProof;
use App\Modules\Identity\Application\TotpSetup;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Passwords;
use Tests\Support\Race;
use Tests\Support\Recovery;
use Tests\Support\Totp;

/*
 * The second factor under REAL concurrency, across two PHP processes and two database connections
 * (method: Tests\Support\Race). The Phase 4 and 5 rule applied to the longer gap MFA opens between the first
 * factor and the session: whatever committed while a sign-in waited on the Account's row lock must be seen
 * AFTER it, and a one-time thing must be one-time whoever else is running.
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
function committedChallenge(string $email = 'ada@example.org'): array
{
    $account = Identity::savedActiveAccount($email);
    Access::grant($account, Role::Guardian);
    $factor = Mfa::enroll($account);
    $current = app(AccountRepository::class)->find($account->id);
    assert($current !== null);

    return [$account, new PendingLogin($account->id, app(CredentialMarker::class)->for($current), SecondFactorNeed::Challenge), $factor];
}

/** @return array<string, string> the worker's arguments for finishing the challenge with an authenticator code */
function workerCode(Account $account, PendingLogin $pending, string $secret): array
{
    return ['account' => $account->id->value, 'marker' => $pending->credentialMarker, 'need' => 'challenge', 'code' => Totp::code($secret)];
}

function completeHere(PendingLogin $pending, SecondFactorProof $proof): void
{
    app(CompleteSecondFactor::class)($pending, $proof, new ClientContext('127.0.0.1', 'first'));
}

it('does not let an Account disabled while its challenge waited obtain a session', function () {
    // The password was accepted, the sign-in is pending, and a disable is committed-pending. The second
    // factor has read nothing yet: when it gets the Account's row the Account is disabled, and must stay so.
    [$account, $pending, $factor] = committedChallenge();

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($account->id),
        'account.disabled', 'mfa_complete', workerCode($account, $pending, $factor['secret']),
    );

    $row = DB::table('accounts')->where('id', $account->id->value)->first();
    expect($race['blocked'])->toBeTrue('the second factor did not wait for the disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and($row?->status)->toBe('disabled')
        ->and($row?->last_login_at)->toBeNull()
        ->and(DB::table('security_events')->where('type', 'authentication.succeeded')->count())->toBe(0)
        ->and(DB::table('account_totp_factors')->value('last_used_step'))->toBeNull()    // nothing was consumed
        ->and(Identity::context(Identity::events('mfa.challenge_failed')[0]))->toBe(['reason' => 'account_not_active', 'during' => 'sign_in']);
});

it('still finishes the sign-in, after waiting, when the concurrent change left the Account alone', function () {
    // The control: the lock makes the challenge WAIT, and it succeeds because nothing it depends on moved.
    // Without it the test above could pass by refusing everything.
    [$account, $pending, $factor] = committedChallenge();

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($account, $pause): void {
            $current = app(AccountRepository::class)->findForUpdate($account->id);
            assert($current !== null);
            app(AccountRepository::class)->save($current);   // an unrelated write to the same row
            $pause();
        }),
        null, 'mfa_complete', workerCode($account, $pending, $factor['secret']),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('accounts')->whereNotNull('last_login_at')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'authentication.succeeded')->count())->toBe(1);
});

it('does not let a password replaced while the challenge waited be finished on the old proof', function () {
    // A reset commits the new credential; the pending sign-in carries a digest of the OLD one.
    [$account, $pending, $factor] = committedChallenge();
    $token = Recovery::tokenFor($account);

    $race = Race::against(
        fn (Closure $pause) => app(ResetPassword::class)(EmailAddress::fromString('ada@example.org'), $token, Passwords::STRONG, new ClientContext('127.0.0.1', 'first')),
        'password.reset_completed', 'mfa_complete', workerCode($account, $pending, $factor['secret']),
    );

    expect($race['blocked'])->toBeTrue('the second factor did not wait for the reset to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('accounts')->whereNotNull('last_login_at')->count())->toBe(0)
        ->and(DB::table('security_events')->where('type', 'authentication.succeeded')->count())->toBe(0)
        ->and(Identity::context(Identity::events('mfa.challenge_failed')[0]))->toBe(['reason' => 'credential_changed', 'during' => 'sign_in']);
});

it('lets exactly one of two simultaneous sign-ins spend the same recovery code', function () {
    // Two half-finished sign-ins for one Account present ONE recovery code. The first spends it and is paused
    // inside its transaction; the second must wait, then find it spent.
    [$account, $pending, $factor] = committedChallenge();
    $code = $factor['codes'][0];

    $race = Race::against(
        fn (Closure $pause) => completeHere($pending, SecondFactorProof::recoveryCode($code)),
        'mfa.recovery_code_used', 'mfa_complete', ['account' => $account->id->value, 'marker' => $pending->credentialMarker, 'need' => 'challenge', 'recovery_code' => $code],
    );

    expect($race['blocked'])->toBeTrue('the second sign-in did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('security_events')->where('type', 'mfa.recovery_code_used')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'authentication.succeeded')->count())->toBe(1)
        ->and(DB::table('account_recovery_codes')->whereNotNull('used_at')->count())->toBe(1)
        ->and(app(RecoveryCodeRepository::class)->remaining($account->id))->toBe(9);
});

it('spends a recovery code atomically even with NO Account lock: the conditional update alone is enough', function () {
    // The two layers behind "one code, one use" are the Account's row lock and the single conditional UPDATE.
    // The race above passes through the first even if this one is broken, so the second is pinned here: a
    // transaction spends the code and pauses, holding no Account lock, and a second process tries the same.
    [$account, , $factor] = committedChallenge();
    $digest = RecoveryCode::fromPresented($factor['codes'][0])->digest($account->id);

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($account, $digest, $pause): void {
            expect(app(RecoveryCodeRepository::class)->consume($account->id, $digest, new DateTimeImmutable('now')))->toBeTrue();
            $pause();
        }),
        null, 'consume_code', ['account' => $account->id->value, 'digest' => $digest],
    );

    expect($race['blocked'])->toBeTrue('a second spend of the same code did not wait on the row')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('account_recovery_codes')->whereNotNull('used_at')->count())->toBe(1);
});

it('does not make spending DIFFERENT recovery codes wait on each other', function () {
    // The control for the test above: it is the ROW that serialises, not the whole table.
    [$account, , $factor] = committedChallenge();
    $first = RecoveryCode::fromPresented($factor['codes'][0])->digest($account->id);
    $second = RecoveryCode::fromPresented($factor['codes'][1])->digest($account->id);

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($account, $first, $pause): void {
            app(RecoveryCodeRepository::class)->consume($account->id, $first, new DateTimeImmutable('now'));
            $pause();
        }),
        null, 'consume_code', ['account' => $account->id->value, 'digest' => $second],
    );

    expect($race['blocked'])->toBeFalse()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('account_recovery_codes')->whereNotNull('used_at')->count())->toBe(2);
});

it('accepts an authenticator code once, even when two sign-ins present it at the same moment', function () {
    // The replay rule (a time step is used once) under concurrency: the first sign-in records the step and is
    // paused before it commits; the second presents the SAME code and must find the step already spent.
    [$account, $pending, $factor] = committedChallenge();
    $code = Totp::code($factor['secret']);

    $race = Race::against(
        fn (Closure $pause) => completeHere($pending, SecondFactorProof::totp($code)),
        'authentication.succeeded', 'mfa_complete', ['account' => $account->id->value, 'marker' => $pending->credentialMarker, 'need' => 'challenge', 'code' => $code],
    );

    expect($race['blocked'])->toBeTrue('the second sign-in did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('security_events')->where('type', 'authentication.succeeded')->count())->toBe(1)
        ->and(Identity::context(Identity::events('mfa.challenge_failed')[0]))->toBe(['reason' => 'invalid_code', 'during' => 'sign_in']);
});

it('does not enrol an Account that was disabled while its enrolment proof waited', function () {
    // The enrolment's proof (a valid code from the pending secret) is presented while a disable is
    // committed-pending. It must not make MFA real for an Account that can no longer sign in.
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::Guardian);
    $current = app(AccountRepository::class)->find($account->id);
    assert($current !== null);
    $pending = new PendingLogin($account->id, app(CredentialMarker::class)->for($current), SecondFactorNeed::Enrollment);
    $setup = app(BeginTotpEnrollment::class)($pending);
    assert($setup instanceof TotpSetup);

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($account->id),
        'account.disabled', 'mfa_confirm_enrollment',
        ['account' => $account->id->value, 'marker' => $pending->credentialMarker, 'code' => Totp::code($setup->secret->reveal())],
    );

    expect($race['blocked'])->toBeTrue('the enrolment did not wait for the disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and(Mfa::isEnrolled($account))->toBeFalse()
        ->and(DB::table('account_recovery_codes')->count())->toBe(0)
        ->and(DB::table('security_events')->where('type', 'mfa.enabled')->count())->toBe(0);
});
