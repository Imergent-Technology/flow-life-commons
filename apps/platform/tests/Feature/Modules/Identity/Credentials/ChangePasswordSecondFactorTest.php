<?php

declare(strict_types=1);

use App\Modules\Identity\Application\AccountSecurityGeneration;
use App\Modules\Identity\Application\ChangePassword;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Application\ResetMultiFactor;
use App\Modules\Identity\Application\SecondFactorProof;
use App\Modules\Identity\Application\SecondFactorRejected;
use App\Modules\Identity\Domain\Account;
use App\Shared\Domain\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Console;
use Tests\Support\Faults;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Passwords;
use Tests\Support\Totp;

/*
 * Changing a password when the Account has an authenticator (G6 WP1-01, owner rulings M1/DP2).
 *
 * An Account with an ACTIVE enrolled authenticator must present the current password AND a fresh second factor
 * (an authenticator code or an unused recovery code). The server decides whether the Account is enrolled, under
 * the Account's row lock; nothing the client says about enrolment is believed. An Account without an
 * authenticator changes its password with the current password alone (tests/.../ChangePasswordTest.php).
 *
 * Independent of the factor, a password change is NOT a new sign-in: the session keeps its original
 * `authenticated_at` and so its absolute expiry, and its recent-security-verification window is not widened.
 *
 * The races, on both engines, are in tests/Concurrency/CredentialRaceTest.php.
 */

const SECOND_FACTOR_CHANGE_PATH = '/api/v1/password/change';

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
    Passwords::breached();
    Mfa::registerProbeRoutes();
});

/**
 * @param  array<string, string>  $proof  `code` or `recovery_code`; none at all for "the password alone"
 * @return TestResponse<Response>
 */
function changeWithFactor(Console $console, array $proof, string $new = Passwords::STRONG, string $current = Identity::PASSWORD): TestResponse
{
    return $console->post(SECOND_FACTOR_CHANGE_PATH, ['current_password' => $current, 'password' => $new, 'password_confirmation' => $new, ...$proof]);
}

/**
 * Everything a refused change must leave exactly as it was.
 *
 * @return array<string, mixed>
 */
function credentialState(Account $account): array
{
    $factor = Mfa::factorRow($account);

    return [
        'password_hash' => DB::table('accounts')->where('id', $account->id->value)->value('password_hash'),
        'last_used_step' => $factor?->last_used_step,
        'recovery_codes_remaining' => Mfa::remainingCodes($account),
        'sessions' => DB::table('sessions')->where('user_id', $account->id->value)->orderBy('id')->pluck('id')->all(),
        'generation' => app(AccountSecurityGeneration::class)->current($account->id),
        'password_changed_events' => count(Identity::events('password.changed')),
        'recovery_used_events' => count(Identity::events('mfa.recovery_code_used')),
    ];
}

/* --- the original flaw: the absolute lifetime ----------------------------------------------------------------- */

it('does NOT extend the 12-hour lifetime of a second-factor session, even with a fresh factor proof', function () {
    // The discriminating regression for WP1-01. Before the fix `reauthenticate()` re-dated `authenticated_at`.
    [$console, , $factor] = Mfa::signedIn();
    $signedIn = $console->me()->assertOk();
    $authenticatedAt = $signedIn->json('session.authenticated_at');
    $expiresAt = $signedIn->json('session.absolute_expires_at');
    $console->advanceWhileActive(11 * 3600);
    $oldCookies = $console->cookieValues();

    changeWithFactor($console, ['code' => Totp::next($factor['secret'])])->assertNoContent();

    // Rotated and still signed in before the original expiry; the instant and the expiry are the ORIGINAL ones.
    Console::replaying($oldCookies)->me()->assertUnauthorized();
    $after = $console->me()->assertOk();
    expect($after->json('session.authenticated_at'))->toBe($authenticatedAt)
        ->and($after->json('session.absolute_expires_at'))->toBe($expiresAt);

    // And the original twelve hours still run out. (Not twelve hours from the change.)
    $console->advanceWhileActive(3600 + 60);
    $console->me()->assertUnauthorized();
});

it('does not widen the recent-security-verification window either', function () {
    // The change proves password AND a factor, but it is not a step-up: only /security/verify (and the other
    // credential-management operations) refresh the window, and the owner's ruling is that this must not.
    [$console, , $factor] = Mfa::signedIn();
    $verifiedUntil = $console->me()->json('mfa.security_verified_until');
    expect($verifiedUntil)->not->toBeNull();
    $console->advanceWhileActive(10 * 60);

    changeWithFactor($console, ['code' => Totp::next($factor['secret'])])->assertNoContent();

    $console->me()->assertJsonPath('mfa.security_verified_until', $verifiedUntil);
    $console->advanceWhileActive(6 * 60);
    $console->get('/api/v1/zz/sensitive')->assertForbidden();   // 16 minutes after the sign-in's proof
});

/* --- the new requirement --------------------------------------------------------------------------------------- */

it('refuses an enrolled Account the password alone, and changes nothing', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $other = new Console;
    $other->loginWithMfa('ada@example.org', Identity::PASSWORD, $factor['secret'])->assertOk();
    $before = credentialState($account);

    // The answer is on the field the person must fill in.
    changeWithFactor($console, [])->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'Enter a code from your authenticator app, or a recovery code.');
    expect(credentialState($account))->toBe($before);
    $console->me()->assertOk();
    $other->me()->assertOk();
    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertStatus(202);   // the password is unchanged
    $failures = Identity::events('mfa.challenge_failed');
    expect($failures)->toHaveCount(1)
        ->and(Identity::context($failures[0]))->toBe(['reason' => 'code_required', 'during' => 'password_change']);
});

it('changes the password of an enrolled Account on the current password and a fresh authenticator code', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $other = new Console;
    $other->loginWithMfa('ada@example.org', Identity::PASSWORD, $factor['secret'])->assertOk();
    $generation = app(AccountSecurityGeneration::class)->current($account->id);

    changeWithFactor($console, ['code' => Totp::next($factor['secret'])])->assertNoContent();

    // The effects of a successful change are all intact: the new password, the other session gone, the generation advanced.
    (new Console)->loginWithMfa('ada@example.org', Passwords::STRONG, $factor['secret'])->assertOk();
    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertUnauthorized();
    $other->me()->assertUnauthorized();
    $console->me()->assertOk();
    expect(app(AccountSecurityGeneration::class)->current($account->id))->toBe($generation + 1)
        ->and(Identity::context(Identity::events('password.changed')[0]))->toBe(['signed_out' => 1]);
});

it('accepts a recovery code instead, spending it exactly once', function () {
    [$console, $account, $factor] = Mfa::signedIn();

    changeWithFactor($console, ['recovery_code' => $factor['codes'][0]])->assertNoContent();

    expect(Mfa::remainingCodes($account))->toBe(9);
    $events = Identity::events('mfa.recovery_code_used');
    expect($events)->toHaveCount(1)
        ->and(Identity::context($events[0]))->toBe(['remaining' => 9, 'during' => 'password_change']);
    // The same code does not work a second time, here or at sign-in.
    changeWithFactor($console, ['recovery_code' => $factor['codes'][0]], Passwords::OTHER, Passwords::STRONG)->assertUnprocessable()
        ->assertJsonPath('errors.recovery_code.0', 'The code is not valid.');
    $next = new Console;
    $next->login('ada@example.org', Passwords::STRONG)->assertStatus(202);
    $next->challengeWithRecoveryCode($factor['codes'][0])->assertUnprocessable();
    expect(Mfa::remainingCodes($account))->toBe(9);
});

it('does not accept an authenticator code twice: the time step it proves is spent', function () {
    [$console, , $factor] = Mfa::signedIn();
    $code = Totp::next($factor['secret']);
    changeWithFactor($console, ['code' => $code])->assertNoContent();

    changeWithFactor($console, ['code' => $code], Passwords::OTHER, Passwords::STRONG)->assertUnprocessable()
        ->assertJsonPath('errors.code.0', 'The code is not valid.');

    (new Console)->login('ada@example.org', Passwords::STRONG)->assertStatus(202);   // still the first change's password
});

it('refuses a wrong, malformed or both-at-once factor, and changes nothing', function (array $proof, string $field) {
    [$console, $account] = Mfa::signedIn();
    $before = credentialState($account);
    /** @var array<string, string> $presented */
    $presented = $proof;

    changeWithFactor($console, $presented)->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(credentialState($account))->toBe($before);
    $console->me()->assertOk();
})->with([
    'a wrong code' => [['code' => '000000'], 'code'],
    'a malformed code' => [['code' => 'not-a-code'], 'code'],
    'a wrong recovery code' => [['recovery_code' => 'AAAAA-BBBBB-CCCCC-DDDDD'], 'recovery_code'],
    'a code and a recovery code together' => [['code' => '123456', 'recovery_code' => 'AAAAA-BBBBB-CCCCC-DDDDD'], 'code'],
]);

it('is decided by the server: an Account WITHOUT an authenticator is not asked for one, and one that sends a code is not hurt', function () {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    // Nothing was enrolled, so the password suffices; a code that came along is not looked at or counted.
    changeWithFactor($console, ['code' => '000000'])->assertNoContent();

    (new Console)->login('ada@example.org', Passwords::STRONG)->assertOk();
    expect(Identity::events('mfa.challenge_failed'))->toBe([]);
});

it('reads enrolment from the Account at the moment of the change: an authenticator enrolled after sign-in is required', function () {
    // (Enrolment advances the security generation, so a real session of that Account would already be over;
    // the use case is asked directly to show that what it consults is the Account, not anything the caller holds.)
    $account = Identity::savedActiveAccount();
    $actor = Actor::user($account->id, $account->personId);
    $change = app(ChangePassword::class);
    $factor = Mfa::enroll($account);

    expect(fn () => $change($actor, Identity::PASSWORD, Passwords::STRONG, 'x', new ClientContext('127.0.0.1', 'test')))
        ->toThrow(SecondFactorRejected::class);
    $change($actor, Identity::PASSWORD, Passwords::STRONG, 'x', new ClientContext('127.0.0.1', 'test'), SecondFactorProof::totp(Totp::next($factor['secret'])));

    (new Console)->login('ada@example.org', Passwords::STRONG)->assertStatus(202);
});

/* --- atomicity: a failed change spends nothing ------------------------------------------------------------------ */

it('spends neither a code nor a recovery code when the current password is wrong', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $before = credentialState($account);

    changeWithFactor($console, ['code' => Totp::next($factor['secret'])], current: 'not the current password')->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');
    changeWithFactor($console, ['recovery_code' => $factor['codes'][0]], current: 'not the current password')->assertUnprocessable();

    expect(credentialState($account))->toBe($before);
    // ...and both are still good.
    changeWithFactor($console, ['recovery_code' => $factor['codes'][0]])->assertNoContent();
});

it('spends nothing when the new password is refused, or the breach check is down', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $before = credentialState($account);
    $code = Totp::next($factor['secret']);

    changeWithFactor($console, ['code' => $code], 'too short')->assertUnprocessable();
    Passwords::checkerDown();
    changeWithFactor($console, ['code' => $code])->assertStatus(503);
    changeWithFactor($console, ['recovery_code' => $factor['codes'][0]])->assertStatus(503);

    expect(credentialState($account))->toBe($before);
    Passwords::breached();
    changeWithFactor($console, ['code' => $code])->assertNoContent();   // the very same code, still unspent
});

it('rolls a spent authenticator step back with everything else when the audit event cannot be written', function () {
    [, $account, $factor] = Mfa::signedIn();
    $before = credentialState($account);
    $actor = Actor::user($account->id, $account->personId);
    Faults::auditFailsAt(1);   // password.changed: the only event a TOTP change writes

    expect(fn () => app(ChangePassword::class)($actor, Identity::PASSWORD, Passwords::STRONG, 'x', new ClientContext('127.0.0.1', 'test'), SecondFactorProof::totp(Totp::next($factor['secret']))))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(credentialState($account))->toBe($before);
});

it('rolls a spent recovery code back, and its audit event, when a LATER step fails', function (string $fault) {
    [, $account, $factor] = Mfa::signedIn();
    $before = credentialState($account);
    $actor = Actor::user($account->id, $account->personId);
    $fault === 'audit' ? Faults::auditFailsAt(2) : Faults::accountSaveFails();   // the code is spent first, then the change fails

    expect(fn () => app(ChangePassword::class)($actor, Identity::PASSWORD, Passwords::STRONG, 'x', new ClientContext('127.0.0.1', 'test'), SecondFactorProof::recoveryCode($factor['codes'][3])))
        ->toThrow(RuntimeException::class);

    expect(credentialState($account))->toBe($before);
})->with(['the audit write' => ['audit'], 'the password save' => ['save']]);

it('treats an Account disabled in flight as no longer signed in, and spends nothing', function () {
    [, $account, $factor] = Mfa::signedIn();
    $actor = Actor::user($account->id, $account->personId);
    $change = app(ChangePassword::class);
    app(DisableAccount::class)($account->id);
    $before = credentialState($account);

    expect(fn () => $change($actor, Identity::PASSWORD, Passwords::STRONG, 'x', new ClientContext('127.0.0.1', 'test'), SecondFactorProof::recoveryCode($factor['codes'][0])))
        ->toThrow(NoLongerAuthenticated::class);

    expect(credentialState($account))->toBe($before);
});

it('refuses, at the use case, an enrolled Account that presents no proof at all', function () {
    [, $account] = Mfa::signedIn();
    $before = credentialState($account);

    expect(fn () => app(ChangePassword::class)(Actor::user($account->id, $account->personId), Identity::PASSWORD, Passwords::STRONG, 'x', new ClientContext('127.0.0.1', 'test')))
        ->toThrow(SecondFactorRejected::class);

    expect(credentialState($account))->toBe($before);
});

it('asks for a factor only while the Account has one: after an administrator reset it does not', function () {
    [$console, $account] = Mfa::signedIn();
    app(ResetMultiFactor::class)->fromServer($account->id);

    // The reset ended the session (ADR 0025), and the Account has no authenticator now: whoever holds the
    // password, asked at the use case directly, is held to what the Account IS, not to what it was.
    app(ChangePassword::class)(Actor::user($account->id, $account->personId), Identity::PASSWORD, Passwords::STRONG, 'x', new ClientContext('127.0.0.1', 'test'));

    expect(Mfa::isEnrolled($account))->toBeFalse();
    $console->me()->assertUnauthorized();
});

/* --- throttling: one count per attempt, not two ---------------------------------------------------------------- */

it('counts each attempt once against the password-change allowance, the factor included', function () {
    config(['identity.credential_throttle.password_change.per_identifier' => 3]);
    [$console, , $factor] = Mfa::signedIn();

    changeWithFactor($console, ['code' => '000000'])->assertUnprocessable();
    changeWithFactor($console, ['code' => '000001'])->assertUnprocessable();
    // Two spent of three: the third, correct, attempt still goes through (a double count would have refused it).
    changeWithFactor($console, ['code' => Totp::next($factor['secret'])])->assertNoContent();
});

it('stops guessing at the factor once the allowance is spent, even for the right code', function () {
    config(['identity.credential_throttle.password_change.per_identifier' => 2]);
    [$console, $account, $factor] = Mfa::signedIn();
    $before = credentialState($account);

    changeWithFactor($console, ['code' => '000000'])->assertUnprocessable();
    changeWithFactor($console, ['code' => '000001'])->assertUnprocessable();
    changeWithFactor($console, ['code' => Totp::next($factor['secret'])])->assertStatus(429);

    expect(credentialState($account))->toBe($before);
});
