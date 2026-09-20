<?php

declare(strict_types=1);

use App\Modules\Identity\Http\RequireRecentSecurityVerification;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * The step-up seam (ADR 0023): `security.verified` demands password AND a second factor proved recently on
 * THIS session. Nothing in production uses it yet; a TEST-ONLY route behind it proves the enforcement edge.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
    Mfa::registerProbeRoutes();
});

const SENSITIVE = '/api/v1/zz/sensitive';

/** @return array<string, string> */
function reproof(string $secret): array
{
    return ['current_password' => Identity::PASSWORD, 'code' => Totp::next($secret)];
}

it('answers 401 to an anonymous caller, not 403: authentication comes first', function () {
    $console = new Console;
    $console->bootstrap();

    $console->get(SENSITIVE)->assertUnauthorized();
});

it('accepts a caller who has just proved password and a second factor by signing in', function () {
    [$console] = Mfa::signedIn();

    $console->get(SENSITIVE)->assertOk();
    $console->me()->assertJsonPath('mfa.security_verified_until', '2026-09-19T12:15:00Z');
});

it('refuses ordinary session authentication alone: a password-only session is not verified', function () {
    Identity::savedActiveAccount();   // holds no role, has no authenticator: a plain password session
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    $console->get(SENSITIVE)->assertForbidden()->assertJson(['verification_required' => true]);
    $console->me()->assertOk()->assertJsonPath('mfa.security_verified_until', null);
});

it('refuses once the proof is more than fifteen minutes old, and to the second', function () {
    [$console] = Mfa::signedIn();

    $console->advanceWhileActive(14 * 60 + 59);
    $console->get(SENSITIVE)->assertOk();

    Console::advance(1);   // exactly 15 minutes since the proof
    $console->get(SENSITIVE)->assertForbidden()->assertJson(['verification_required' => true]);
    $console->me()->assertOk()->assertJsonPath('mfa.security_verified_until', null);   // still signed in
});

it('is restored by proving again, and only by proving again', function () {
    [$console, , $factor] = Mfa::signedIn();
    $console->advanceWhileActive(20 * 60);
    $console->get(SENSITIVE)->assertForbidden();

    // Being active does not help, and neither does a wrong proof.
    $console->me()->assertOk();
    $console->get(SENSITIVE)->assertForbidden();
    $console->post('/api/v1/security/verify', ['current_password' => Identity::PASSWORD, 'code' => '000000'])->assertUnprocessable();
    $console->get(SENSITIVE)->assertForbidden();

    $console->post('/api/v1/security/verify', reproof($factor['secret']))->assertNoContent();

    $console->get(SENSITIVE)->assertOk();
    $console->me()->assertJsonPath('mfa.security_verified_until', Carbon::now()->addMinutes(15)->toIso8601ZuluString());
});

it('accepts a recovery code as the second proof', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $console->advanceWhileActive(20 * 60);

    $console->post('/api/v1/security/verify', ['current_password' => Identity::PASSWORD, 'recovery_code' => $factor['codes'][0]])->assertNoContent();

    $console->get(SENSITIVE)->assertOk();
    expect(Mfa::remainingCodes($account))->toBe(9);
});

it('needs BOTH proofs: a password alone or a code alone verifies nothing', function () {
    [$console, , $factor] = Mfa::signedIn();
    $console->advanceWhileActive(20 * 60);

    $console->post('/api/v1/security/verify', ['current_password' => Identity::PASSWORD])->assertUnprocessable();
    $console->post('/api/v1/security/verify', ['code' => Totp::next($factor['secret'])])->assertUnprocessable();
    $console->post('/api/v1/security/verify', ['current_password' => 'not it', 'code' => Totp::next($factor['secret'])])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $console->get(SENSITIVE)->assertForbidden();
});

it('changes nothing else: the session keeps its authentication time, and its id is rotated', function () {
    [$console, , $factor] = Mfa::signedIn();
    $authenticatedAt = $console->me()->json('session.authenticated_at');
    $console->advanceWhileActive(20 * 60);
    $before = $console->cookieValues();

    $console->post('/api/v1/security/verify', reproof($factor['secret']))->assertNoContent();

    expect($console->me()->json('session.authenticated_at'))->toBe($authenticatedAt);
    Console::replaying($before)->me()->assertUnauthorized();
});

it('is per session: another session of the same Account has its own', function () {
    [$console, , $factor] = Mfa::signedIn();
    $other = new Console;
    $other->loginWithMfa('ada@example.org', Identity::PASSWORD, $factor['secret'])->assertOk();
    $console->advanceWhileActive(20 * 60);
    $other->me()->assertOk();

    $console->post('/api/v1/security/verify', reproof($factor['secret']))->assertNoContent();

    $console->get(SENSITIVE)->assertOk();
    $other->get(SENSITIVE)->assertForbidden();
});

it('distrusts a verification instant that is missing, malformed or in the future', function (mixed $value) {
    [$console] = Mfa::signedIn();
    $console->get(SENSITIVE)->assertOk();

    $console->tamperSession(function (Store $session) use ($value): void {
        $value === null ? $session->forget('security_verified_at') : $session->put('security_verified_at', $value);
    });

    $console->get(SENSITIVE)->assertForbidden();
})->with([
    'missing' => [null],
    'a string' => ['yesterday'],
    'an array' => [[1]],
    'a day in the future' => [1_800_000_000 + 86_400],
    'a negative number' => [-1],
]);

it('does not take "recently verified" from anything the client sends', function () {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    $console->get(SENSITIVE.'?security_verified_at='.time(), ['X-Security-Verified' => '1', 'security_verified_at' => (string) time()])->assertForbidden();
});

it('rate limits verification attempts, so a stolen session cannot guess at the password', function () {
    [$console, , $factor] = Mfa::signedIn();
    config(['identity.credential_throttle.security_verification.per_identifier' => 2]);

    $console->post('/api/v1/security/verify', ['current_password' => 'guess', 'code' => '000000'])->assertUnprocessable();
    $console->post('/api/v1/security/verify', ['current_password' => 'guess', 'code' => '000000'])->assertUnprocessable();

    $console->post('/api/v1/security/verify', reproof($factor['secret']))->assertStatus(429);
});

it('records a verification with the method, and nothing secret', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $console->advanceWhileActive(20 * 60);
    $code = Totp::next($factor['secret']);
    $console->post('/api/v1/security/verify', ['current_password' => Identity::PASSWORD, 'code' => $code])->assertNoContent();

    $events = Identity::events('security.reverified');
    expect($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($account->id->value)
        ->and(Identity::context($events[0]))->toBe(['method' => 'totp']);
    Mfa::assertAbsent(Mfa::auditText(), $code, Identity::PASSWORD);
});

it('exposes the seam only as an alias, so other modules never import Identity\'s Http layer', function () {
    $kernel = app(Kernel::class);
    $aliases = $kernel->getMiddlewareAliases();

    expect($aliases)->toHaveKey('security.verified')
        ->and($aliases['security.verified'])->toBe(RequireRecentSecurityVerification::class);
});
