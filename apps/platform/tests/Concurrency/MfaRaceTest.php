<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\BeginTotpEnrollment;
use App\Modules\Identity\Application\ChangePassword;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\CompleteSecondFactor;
use App\Modules\Identity\Application\ConfirmTotpEnrollment;
use App\Modules\Identity\Application\CredentialMarker;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Application\PendingLogin;
use App\Modules\Identity\Application\ResetMultiFactor;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Application\SecondFactorNeed;
use App\Modules\Identity\Application\SecondFactorProof;
use App\Modules\Identity\Application\SecondFactorRejected;
use App\Modules\Identity\Application\TotpSetup;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Shared\Domain\Actor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
    Access::grant($account, Role::GuardianFull);
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
    Access::grant($account, Role::GuardianFull);
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

/* --- changing a password on a second factor (G6 WP1-01) -------------------------------------------------- */

/** A password change in this process, as the first (paused) operation of a race. */
function changeHereWith(Account $account, string $current, string $new, ?SecondFactorProof $proof): void
{
    app(ChangePassword::class)(
        Actor::user($account->id, $account->personId), $current, $new, 'the-first-callers-session', new ClientContext('127.0.0.1', 'first'), $proof,
    );
}

/**
 * @param  array<string, string>  $proof  `code` or `recovery_code`, for an Account that has an authenticator
 * @return array<string, string> the worker's arguments for a password change
 */
function workerChange(Account $account, string $current, string $new, array $proof = []): array
{
    return ['account' => $account->id->value, 'person' => $account->personId->value, 'current' => $current, 'password' => $new, ...$proof];
}

it('lets exactly one of two simultaneous password changes spend the same recovery code', function () {
    // Two sessions of one Account change the password with the SAME recovery code. The first spends it and is paused
    // inside its transaction; the second (which presents the password the first is setting, so that the factor is
    // the only thing it can fail on) must wait on the Account's row, then find the code spent.
    [$account, , $factor] = committedChallenge();
    $code = $factor['codes'][0];

    $race = Race::against(
        fn (Closure $pause) => changeHereWith($account, Identity::PASSWORD, Passwords::STRONG, SecondFactorProof::recoveryCode($code)),
        'password.changed', 'change', workerChange($account, Passwords::STRONG, Passwords::OTHER, ['recovery_code' => $code]),
    );

    $hash = Identity::scalar('accounts', 'password_hash');
    expect($race['blocked'])->toBeTrue('the second change did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(SecondFactorRejected::class)
        ->and(DB::table('security_events')->where('type', 'password.changed')->count())->toBe(1)
        ->and(DB::table('security_events')->where('type', 'mfa.recovery_code_used')->count())->toBe(1)
        ->and(DB::table('account_recovery_codes')->whereNotNull('used_at')->count())->toBe(1)
        ->and(app(RecoveryCodeRepository::class)->remaining($account->id))->toBe(9)
        ->and(Hash::check(Passwords::STRONG, $hash))->toBeTrue()
        ->and(Hash::check(Passwords::OTHER, $hash))->toBeFalse()
        ->and(Identity::context(Identity::events('mfa.challenge_failed')[0]))->toBe(['reason' => 'invalid_code', 'during' => 'password_change']);
});

it('accepts an authenticator code once across two simultaneous password changes', function () {
    [$account, , $factor] = committedChallenge();
    $code = Totp::code($factor['secret']);

    $race = Race::against(
        fn (Closure $pause) => changeHereWith($account, Identity::PASSWORD, Passwords::STRONG, SecondFactorProof::totp($code)),
        'password.changed', 'change', workerChange($account, Passwords::STRONG, Passwords::OTHER, ['code' => $code]),
    );

    expect($race['blocked'])->toBeTrue('the second change did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(SecondFactorRejected::class)
        ->and(DB::table('security_events')->where('type', 'password.changed')->count())->toBe(1)
        ->and(Hash::check(Passwords::STRONG, Identity::scalar('accounts', 'password_hash')))->toBeTrue();
});

it('does not let a password-only change slip past an authenticator that was enrolled while it waited', function () {
    // The change is asked of an Account that had NO authenticator when the request began. An enrolment commits
    // first; the change gets the Account's row afterwards and must see the Account as it is NOW, and refuse.
    $account = Identity::savedActiveAccount();
    Access::grant($account, Role::GuardianFull);
    $current = app(AccountRepository::class)->find($account->id);
    assert($current !== null);
    $pending = new PendingLogin($account->id, app(CredentialMarker::class)->for($current), SecondFactorNeed::Enrollment);
    $setup = app(BeginTotpEnrollment::class)($pending);
    assert($setup instanceof TotpSetup);
    $before = Identity::scalar('accounts', 'password_hash');

    $race = Race::against(
        fn (Closure $pause) => app(ConfirmTotpEnrollment::class)($pending, SecondFactorProof::totp(Totp::code($setup->secret->reveal())), new ClientContext('127.0.0.1', 'first')),
        'mfa.enabled', 'change', workerChange($account, Identity::PASSWORD, Passwords::STRONG),
    );

    expect($race['blocked'])->toBeTrue('the change did not wait for the enrolment to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(SecondFactorRejected::class)
        ->and(Mfa::isEnrolled($account))->toBeTrue()
        ->and(Identity::scalar('accounts', 'password_hash'))->toBe($before)
        ->and(DB::table('security_events')->where('type', 'password.changed')->count())->toBe(0);
});

it('still changes the password, after waiting, when the concurrent write left the Account without an authenticator', function () {
    // The control for the test above: the wait is the row lock, and an unrelated write does not turn it into a refusal.
    $account = Identity::savedActiveAccount();

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($account, $pause): void {
            $locked = app(AccountRepository::class)->findForUpdate($account->id);
            assert($locked !== null);
            app(AccountRepository::class)->save($locked);   // an unrelated write to the same row
            $pause();
        }),
        null, 'change', workerChange($account, Identity::PASSWORD, Passwords::STRONG),
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exit'])->toBe(0)
        ->and(Hash::check(Passwords::STRONG, Identity::scalar('accounts', 'password_hash')))->toBeTrue();
});

it('holds a change to what the Account is once an administrator reset has committed: no authenticator, no factor needed', function () {
    [$account] = committedChallenge();

    $race = Race::against(
        fn (Closure $pause) => app(ResetMultiFactor::class)->fromServer($account->id),
        'mfa.reset_from_server', 'change', workerChange($account, Identity::PASSWORD, Passwords::STRONG),
    );

    expect($race['blocked'])->toBeTrue('the change did not wait for the reset to commit')
        ->and($race['exit'])->toBe(0)
        ->and(Mfa::isEnrolled($account))->toBeFalse()
        ->and(Hash::check(Passwords::STRONG, Identity::scalar('accounts', 'password_hash')))->toBeTrue();
});

it('spends no recovery code for a change that finds the Account disabled once it gets the row', function () {
    [$account, , $factor] = committedChallenge();
    $before = Identity::scalar('accounts', 'password_hash');

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($account->id),
        'account.disabled', 'change', workerChange($account, Identity::PASSWORD, Passwords::STRONG, ['recovery_code' => $factor['codes'][0]]),
    );

    expect($race['blocked'])->toBeTrue('the change did not wait for the disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(NoLongerAuthenticated::class)
        ->and(app(RecoveryCodeRepository::class)->remaining($account->id))->toBe(10)
        ->and(Identity::scalar('accounts', 'password_hash'))->toBe($before)
        ->and(DB::table('security_events')->where('type', 'password.changed')->count())->toBe(0);
});

it('spends no recovery code for a change whose password was replaced while it waited', function () {
    // A reset commits a new password. The change, presenting the old one with a perfectly good recovery code, must
    // be refused on the password and must not have spent the code on the way.
    [$account, , $factor] = committedChallenge();
    $token = Recovery::tokenFor($account);

    $race = Race::against(
        fn (Closure $pause) => app(ResetPassword::class)(EmailAddress::fromString('ada@example.org'), $token, Passwords::OTHER, new ClientContext('127.0.0.1', 'first')),
        'password.reset_completed', 'change', workerChange($account, Identity::PASSWORD, Passwords::STRONG, ['recovery_code' => $factor['codes'][0]]),
    );

    expect($race['blocked'])->toBeTrue('the change did not wait for the reset to commit')
        ->and($race['exit'])->toBe(2)
        ->and(app(RecoveryCodeRepository::class)->remaining($account->id))->toBe(10)
        ->and(Hash::check(Passwords::OTHER, Identity::scalar('accounts', 'password_hash')))->toBeTrue()
        ->and(DB::table('security_events')->where('type', 'password.changed')->count())->toBe(0);
});
