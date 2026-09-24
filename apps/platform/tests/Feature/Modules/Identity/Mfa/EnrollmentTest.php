<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\RecoveryCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Passwords;
use Tests\Support\Recovery;
use Tests\Support\Totp;

/*
 * Enrolling an authenticator (ADR 0023): a Console Account without a second factor is put into a narrow
 * enrolment state after its password, and gets a session only once it PROVES the secret it was shown.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
    Mfa::registerProbeRoutes();
});

/** Signs in with the password and returns the Console, holding a pending enrolment. */
function pendingEnrollment(string $email = 'ada@example.org'): Console
{
    $console = new Console;
    $console->login($email, Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);

    return $console;
}

/** @return array{string, string} the secret and the provisioning URI */
function setup(Console $console): array
{
    $response = $console->post('/api/v1/mfa/enrollment')->assertOk();

    return [Mfa::text($response->json('secret')), Mfa::text($response->json('otpauth_uri'))];
}

it('does not sign in, on the password alone, an Account whose access needs a second factor', function () {
    $account = Mfa::guardian();
    $console = new Console;

    $response = $console->login('ada@example.org', Identity::PASSWORD);

    // 202, not 200: the password was right, and NOTHING has been established.
    $response->assertStatus(202)->assertExactJson(['next' => 'enrollment', 'expires_at' => '2026-09-19T12:10:00Z']);
    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0)   // no authenticated session row
        ->and(app(AccountRepository::class)->find($account->id)?->lastLoginAt)->toBeNull()
        ->and(Identity::events('authentication.succeeded'))->toBe([]);
    $console->me()->assertUnauthorized();                                       // no identity
    $console->get('/api/v1/zz/console')->assertUnauthorized();                  // and no authorization
});

it('answers a wrong password exactly as it always did, so nothing about the second factor leaks', function () {
    Mfa::guardian();
    Identity::savedActiveAccount('other@example.org');

    $wrong = (new Console)->login('ada@example.org', 'not the password')->assertUnauthorized();
    $unknown = (new Console)->login('nobody@example.org', 'not the password')->assertUnauthorized();

    expect($wrong->getContent())->toBe($unknown->getContent());
});

it('leaves an Account whose access needs no second factor on a plain password sign-in', function () {
    Identity::savedActiveAccount();   // no role: nothing here reaches the Console

    $login = (new Console)->login('ada@example.org', Identity::PASSWORD)->assertOk();

    expect($login->json('mfa'))->toBe(['enrolled' => false, 'recovery_codes_remaining' => 0, 'security_verified_until' => null]);
});

it('generates a secret and shows it once, storing it only encrypted and as PENDING', function () {
    $account = Mfa::guardian();
    $console = pendingEnrollment();

    $response = $console->post('/api/v1/mfa/enrollment')->assertOk();
    $secret = Mfa::text($response->json('secret'));
    $uri = Mfa::text($response->json('otpauth_uri'));

    expect($secret)->toMatch('/^[A-Z2-7]{32}$/D')
        ->and($uri)->toStartWith('otpauth://totp/');
    expect($uri)->toContain('secret='.$secret);
    Mfa::assertAbsent($uri, 'http');
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');

    $row = Mfa::factorRow($account);
    expect($row?->secret_ciphertext)->toBeNull()                                  // nothing active
        ->and($row?->enrolled_at)->toBeNull()
        ->and($row?->pending_secret_ciphertext)->not->toBeNull()
        ->and($row?->pending_secret_ciphertext)->not->toContain($secret)          // never in plain text
        ->and(Crypt::decryptString((string) $row?->pending_secret_ciphertext))->toBe($secret)   // real authenticated encryption
        ->and(Mfa::isEnrolled($account))->toBeFalse();
});

it('reports the SIGN-IN expiry when enrolling at login, which ends before the secret does', function () {
    Mfa::guardian();
    $console = pendingEnrollment();

    $response = $console->post('/api/v1/mfa/enrollment')->assertOk();

    // 10 minutes from the password (the pending sign-in), not the 15 the pending secret alone could wait:
    // a person enrolling at login is bound by the tighter of the two, and is told that one.
    expect($response->json('expires_at'))->toBe('2026-09-19T12:10:00Z')
        ->and(array_keys((array) $response->json()))->toEqualCanonicalizing(['secret', 'otpauth_uri', 'expires_at']);
});

it('does not enrol anything by generating a secret: the Account is exactly as it was', function () {
    $account = Mfa::guardian();
    setup(pendingEnrollment());

    // Signing in again is still an enrolment, not a challenge, and there is still no session.
    $again = new Console;
    $again->login('ada@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);
    $again->me()->assertUnauthorized();
    expect(Mfa::isEnrolled($account))->toBeFalse();
});

it('leaves MFA disabled, and no session, after a wrong code', function () {
    $account = Mfa::guardian();
    $console = pendingEnrollment();
    [$secret] = setup($console);

    $console->post('/api/v1/mfa/enrollment/confirm', ['code' => '000000'])
        ->assertUnprocessable()->assertJsonPath('errors.code.0', 'The code is not valid.');

    expect(Mfa::isEnrolled($account))->toBeFalse()
        ->and(Mfa::factorRow($account)?->secret_ciphertext)->toBeNull()
        ->and(DB::table('account_recovery_codes')->count())->toBe(0)
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
    $console->me()->assertUnauthorized();

    // The pending secret is still there to try again with.
    $console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($secret)])->assertOk();
});

it('enrols on a valid code, returns ten recovery codes once, and signs the person in', function () {
    $account = Mfa::guardian();
    $console = pendingEnrollment();
    [$secret] = setup($console);

    $response = $console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($secret)])->assertOk();
    $codes = Mfa::texts($response->json('recovery_codes'));

    expect($codes)->toBeArray()->toHaveCount(10)
        ->and(array_unique($codes))->toHaveCount(10)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Mfa::isEnrolled($account))->toBeTrue()
        ->and(Mfa::remainingCodes($account))->toBe(10);

    // Signed in, with the second factor recorded.
    $me = $console->me()->assertOk();
    expect($me->json('capabilities'))->toBe(['console.access'])
        ->and($me->json('mfa.enrolled'))->toBeTrue()
        ->and($me->json('mfa.recovery_codes_remaining'))->toBe(10)
        ->and($me->json('mfa.security_verified_until'))->toBe('2026-09-19T12:15:00Z');
    $console->get('/api/v1/zz/actor')->assertOk()->assertJson(['via' => 'session_second_factor']);
    $console->get('/api/v1/zz/console')->assertOk();
    expect(app(AccountRepository::class)->find($account->id)?->lastLoginAt)->not->toBeNull();
});

it('shows the recovery codes exactly once: nothing afterwards can return them', function () {
    Mfa::guardian();
    $console = pendingEnrollment();
    [$secret] = setup($console);
    $codes = Mfa::texts($console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($secret)])->assertOk()->json('recovery_codes'));

    // No response after that one contains any of them: /me reports only how many remain.
    foreach ([$console->me(), $console->get('/api/v1/zz/actor')] as $response) {
        foreach ($codes as $code) {
            Mfa::assertAbsent((string) $response->getContent(), str_replace('-', '', (string) $code), (string) $code);
        }
    }
    // There is nothing to ask again: the enrolment endpoints need a pending sign-in, which no longer exists.
    $console->post('/api/v1/mfa/enrollment')->assertUnauthorized();
    $console->post('/api/v1/mfa/enrollment/confirm', ['code' => '123456'])->assertUnauthorized();
});

it('stores the recovery codes only as one-way digests, so a stolen table gives nothing to use', function () {
    $account = Mfa::guardian();
    $console = pendingEnrollment();
    [$secret] = setup($console);
    $codes = Mfa::texts($console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($secret)])->assertOk()->json('recovery_codes'));

    $stored = DB::table('account_recovery_codes')->where('account_id', $account->id->value)->pluck('code_hash')->all();
    $expected = array_map(fn ($code): string => RecoveryCode::fromPresented((string) $code)->digest($account->id), $codes);

    expect($stored)->toEqualCanonicalizing($expected);
    foreach ($codes as $code) {
        $plain = str_replace('-', '', (string) $code);
        expect(DB::table('account_recovery_codes')->where('code_hash', $plain)->count())->toBe(0)
            ->and(json_encode(DB::table('account_recovery_codes')->get()->all()))->not->toContain($plain);
    }
});

it('never returns the secret once enrolment is confirmed', function () {
    $account = Mfa::guardian();
    $console = pendingEnrollment();
    [$secret, $uri] = setup($console);
    $console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($secret)])->assertOk();

    foreach ([$console->me(), $console->get('/api/v1/zz/actor')] as $response) {
        Mfa::assertAbsent((string) $response->getContent(), $secret, 'otpauth');
    }
    // At rest it is the encrypted active secret; nothing is pending any more.
    $row = Mfa::factorRow($account);
    expect($row?->pending_secret_ciphertext)->toBeNull()
        ->and($row?->secret_ciphertext)->not->toContain($secret)
        ->and(Crypt::decryptString((string) $row?->secret_ciphertext))->toBe($secret);
});

it('lets an abandoned enrolment be restarted, replacing the unconfirmed secret and stranding no one', function () {
    $account = Mfa::guardian();
    [$first] = setup(pendingEnrollment());

    // The first attempt is abandoned. Later: password again, a NEW secret.
    $console = pendingEnrollment();
    [$second] = setup($console);
    expect($second)->not->toBe($first);

    // The abandoned secret is gone: a code for it proves nothing.
    $console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($first)])->assertUnprocessable();
    expect(Mfa::isEnrolled($account))->toBeFalse();
    $console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($second)])->assertOk();
    expect(Mfa::isEnrolled($account))->toBeTrue();
});

it('lets the pending enrolment expire with the pending sign-in, and then nothing can be confirmed', function () {
    $account = Mfa::guardian();
    $console = pendingEnrollment();
    [$secret] = setup($console);

    Console::advance(601);   // 10 minutes and a second after the password

    $console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($secret)])->assertUnauthorized();
    expect(Mfa::isEnrolled($account))->toBeFalse();
});

it('will not enrol an Account that was disabled after its password was accepted', function () {
    $account = Mfa::guardian();
    $console = pendingEnrollment();
    [$secret] = setup($console);

    app(DisableAccount::class)($account->id);

    $console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($secret)])->assertUnauthorized();
    expect(Mfa::isEnrolled($account))->toBeFalse()
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
});

it('will not enrol on a password that was replaced after it was accepted', function () {
    $account = Mfa::guardian();
    $console = pendingEnrollment();
    [$secret] = setup($console);

    app(ResetPassword::class)(EmailAddress::fromString('ada@example.org'), Recovery::tokenFor($account), Passwords::STRONG, new ClientContext('127.0.0.1', 'test'));

    $console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($secret)])->assertUnauthorized();
    expect(Mfa::isEnrolled($account))->toBeFalse();
    expect(Identity::events('mfa.challenge_failed'))->toHaveCount(1);
    expect(Identity::context(Identity::events('mfa.challenge_failed')[0]))->toBe(['reason' => 'credential_changed', 'during' => 'sign_in']);
});

it('does not offer enrolment to a pending challenge: an Account that has an authenticator is challenged', function () {
    $account = Mfa::guardian();
    Mfa::enroll($account);
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'challenge']);

    // Wrong step: it would replace the authenticator, which needs fresh proof, not just a password.
    $console->post('/api/v1/mfa/enrollment')->assertUnauthorized();
    expect(Mfa::factorRow($account)?->pending_secret_ciphertext)->toBeNull();
});

it('records the enrolment and the sign-in, and nothing secret', function () {
    $account = Mfa::guardian();
    $console = pendingEnrollment();
    [$secret, $uri] = setup($console);
    $code = Totp::next($secret);
    $codes = Mfa::texts($console->post('/api/v1/mfa/enrollment/confirm', ['code' => $code])->assertOk()->json('recovery_codes'));

    $enabled = Identity::events('mfa.enabled');
    expect($enabled)->toHaveCount(1)
        ->and($enabled[0]->subject_account_id)->toBe($account->id->value)
        ->and(Identity::context($enabled[0]))->toBe(['method' => 'totp'])
        ->and(Identity::context(Identity::events('authentication.succeeded')[0]))->toBe(['method' => 'password', 'second_factor' => 'enrollment']);

    $audit = Mfa::auditText();
    foreach ([$secret, $uri, $code, Identity::PASSWORD, ...$codes, ...array_map(fn ($c) => str_replace('-', '', (string) $c), $codes)] as $forbidden) {
        expect($audit)->not->toContain((string) $forbidden);
    }
    foreach (DB::table('account_recovery_codes')->pluck('code_hash') as $digest) {
        Mfa::assertAbsent($audit, Mfa::text($digest));
    }
});

it('does not encrypt the same secret to the same text twice', function () {
    Mfa::guardian();
    [$secret] = setup(pendingEnrollment());
    $stored = Mfa::text(DB::table('account_totp_factors')->value('pending_secret_ciphertext'));

    // A fresh IV each time: equal secrets are not visible as equal ciphertexts, and re-encrypting differs.
    expect(Crypt::encryptString($secret))->not->toBe($stored)
        ->and(Crypt::decryptString($stored))->toBe($secret);
});
