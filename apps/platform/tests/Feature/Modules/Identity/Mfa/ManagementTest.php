<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\RecoveryCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * Managing the second factor once it exists (ADR 0023): regenerating recovery codes and replacing the
 * authenticator both need FRESH proof (the current password and a second factor), never a session alone.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
    Mfa::registerProbeRoutes();
});

/** @return array<string, string> */
function proof(string $secret): array
{
    return ['current_password' => Identity::PASSWORD, 'code' => Totp::next($secret)];
}

/* --- recovery codes ------------------------------------------------------------------------------- */

it('regenerates recovery codes on fresh proof, showing the new ones once', function () {
    [$console, $account, $factor] = Mfa::signedIn();

    $response = $console->post('/api/v1/mfa/recovery-codes', proof($factor['secret']))->assertOk();
    $codes = Mfa::texts($response->json('recovery_codes'));

    expect($codes)->toHaveCount(10)->and(array_unique($codes))->toHaveCount(10)
        ->and(array_intersect($codes, $factor['codes']))->toBe([])
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(Mfa::remainingCodes($account))->toBe(10);
    $stored = DB::table('account_recovery_codes')->where('account_id', $account->id->value)->pluck('code_hash')->all();
    expect($stored)->toEqualCanonicalizing(array_map(fn ($c) => RecoveryCode::fromPresented((string) $c)->digest($account->id), $codes));
    expect(Identity::context(Identity::events('mfa.recovery_codes_regenerated')[0]))->toBe(['count' => 10]);
});

it('kills every old recovery code, used or not, when new ones are issued', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    // One is spent before the regeneration; the rest are unused.
    $spender = new Console;
    $spender->login('ada@example.org', Identity::PASSWORD)->assertStatus(202);
    $spender->challengeWithRecoveryCode($factor['codes'][0])->assertOk();

    $console->post('/api/v1/mfa/recovery-codes', proof($factor['secret']))->assertOk();

    foreach ([0, 1, 9] as $index) {
        $next = new Console;
        $next->login('ada@example.org', Identity::PASSWORD)->assertStatus(202);
        $next->challengeWithRecoveryCode($factor['codes'][$index])->assertUnprocessable();
    }
    expect(Mfa::remainingCodes($account))->toBe(10);
});

it('gives the new codes their full use', function () {
    [$console, , $factor] = Mfa::signedIn();
    $new = Mfa::texts($console->post('/api/v1/mfa/recovery-codes', proof($factor['secret']))->assertOk()->json('recovery_codes'));

    $next = new Console;
    $next->login('ada@example.org', Identity::PASSWORD)->assertStatus(202);
    $next->challengeWithRecoveryCode($new[4])->assertOk();
});

it('refuses to regenerate on a session alone', function () {
    [$console, $account, $factor] = Mfa::signedIn();

    $console->post('/api/v1/mfa/recovery-codes', [])->assertUnprocessable();
    $console->post('/api/v1/mfa/recovery-codes', ['current_password' => Identity::PASSWORD])->assertUnprocessable();   // password only
    $console->post('/api/v1/mfa/recovery-codes', ['code' => Totp::next($factor['secret'])])->assertUnprocessable();     // code only

    expect(Mfa::remainingCodes($account))->toBe(10)
        ->and(DB::table('account_recovery_codes')->whereNotNull('used_at')->count())->toBe(0);
    foreach ($factor['codes'] as $code) {
        expect(DB::table('account_recovery_codes')->where('code_hash', RecoveryCode::fromPresented($code)->digest($account->id))->count())->toBe(1);
    }
});

it('refuses to regenerate for a wrong password, changing nothing', function () {
    [$console, , $factor] = Mfa::signedIn();

    $console->post('/api/v1/mfa/recovery-codes', ['current_password' => 'not the password', 'code' => Totp::next($factor['secret'])])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $again = new Console;
    $again->login('ada@example.org', Identity::PASSWORD)->assertStatus(202);
    $again->challengeWithRecoveryCode($factor['codes'][0])->assertOk();   // the old codes are all still good
});

it('refuses to regenerate for a wrong code, changing nothing, and records the failure', function () {
    [$console, $account, $factor] = Mfa::signedIn();

    $console->post('/api/v1/mfa/recovery-codes', ['current_password' => Identity::PASSWORD, 'code' => '000000'])
        ->assertUnprocessable()->assertJsonPath('errors.code.0', 'The code is not valid.');

    expect(Mfa::remainingCodes($account))->toBe(10);
    expect(Identity::events('mfa.recovery_codes_regenerated'))->toBe([]);
    $failed = Identity::events('mfa.challenge_failed');
    expect($failed)->toHaveCount(1)
        ->and(Identity::context($failed[0]))->toBe(['reason' => 'invalid_code', 'during' => 'security_verification']);
});

it('accepts a recovery code as the second proof, spending it before the codes are replaced', function () {
    [$console, $account, $factor] = Mfa::signedIn();

    $response = $console->post('/api/v1/mfa/recovery-codes', ['current_password' => Identity::PASSWORD, 'recovery_code' => $factor['codes'][0]])->assertOk();

    expect($response->json('recovery_codes'))->toHaveCount(10)->and(Mfa::remainingCodes($account))->toBe(10);
    expect(Identity::events('mfa.recovery_code_used'))->toHaveCount(1);
});

it('does not let an anonymous caller near it', function () {
    [, , $factor] = Mfa::signedIn();
    $stranger = new Console;
    $stranger->bootstrap();

    $stranger->post('/api/v1/mfa/recovery-codes', proof($factor['secret']))->assertUnauthorized();
    $stranger->post('/api/v1/mfa/authenticator', proof($factor['secret']))->assertUnauthorized();
    $stranger->post('/api/v1/mfa/authenticator/confirm', ['code' => '123456'])->assertUnauthorized();
    $stranger->post('/api/v1/security/verify', proof($factor['secret']))->assertUnauthorized();
});

it('rotates the session after fresh proof: the id used before stops working', function () {
    [$console, , $factor] = Mfa::signedIn();
    $before = $console->cookieValues();

    $console->post('/api/v1/mfa/recovery-codes', proof($factor['secret']))->assertOk();

    Console::replaying($before)->me()->assertUnauthorized();
    $console->me()->assertOk();
});

it('rate limits proof attempts, so a stolen session cannot be used to guess the password', function () {
    [$console, , $factor] = Mfa::signedIn();
    config(['identity.credential_throttle.security_verification.per_identifier' => 3]);

    for ($i = 0; $i < 3; $i++) {
        $console->post('/api/v1/mfa/recovery-codes', ['current_password' => 'guess '.$i, 'code' => '000000'])->assertUnprocessable();
    }

    $console->post('/api/v1/mfa/recovery-codes', proof($factor['secret']))->assertStatus(429);
    expect(Identity::events('authentication.rate_limited'))->toHaveCount(1);
});

/* --- replacing the authenticator ---------------------------------------------------------------- */

it('replaces the authenticator: proof to start, a new secret pending, and only a code from it switches', function () {
    [$console, $account, $factor] = Mfa::signedIn();

    $begun = $console->post('/api/v1/mfa/authenticator', proof($factor['secret']))->assertOk();
    $new = Mfa::text($begun->json('secret'));
    expect($new)->toMatch('/^[A-Z2-7]{32}$/D');
    expect($new === $factor['secret'])->toBeFalse();
    expect(Mfa::text($begun->json('otpauth_uri')))->toStartWith('otpauth://totp/');
    expect((string) $begun->headers->get('Cache-Control'))->toContain('no-store');

    // Still the old authenticator, until the new one is proved.
    $row = Mfa::factorRow($account);
    expect(Crypt::decryptString((string) $row?->secret_ciphertext))->toBe($factor['secret'])
        ->and(Crypt::decryptString((string) $row?->pending_secret_ciphertext))->toBe($new)
        ->and($row?->pending_secret_ciphertext)->not->toContain($new);

    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => Totp::next($new)])->assertNoContent();

    $row = Mfa::factorRow($account);
    expect(Crypt::decryptString((string) $row?->secret_ciphertext))->toBe($new)
        ->and($row?->pending_secret_ciphertext)->toBeNull();
});

it('keeps the old authenticator working until the new one is proved, so a failed replacement strands no one', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $new = Mfa::text($console->post('/api/v1/mfa/authenticator', proof($factor['secret']))->assertOk()->json('secret'));

    // A wrong code for the new secret changes nothing...
    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => '000000'])->assertUnprocessable()->assertJsonPath('errors.code.0', 'The code is not valid.');
    // ...and a code from the OLD secret does not prove the NEW one.
    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => Totp::next($factor['secret'])])->assertUnprocessable();

    // The old authenticator still signs in.
    $other = new Console;
    $other->loginWithMfa('ada@example.org', Identity::PASSWORD, $factor['secret'])->assertOk();
    expect(Crypt::decryptString((string) Mfa::factorRow($account)?->secret_ciphertext))->toBe($factor['secret']);
});

it('stops the old authenticator working from the moment the new one is proved, and starts the new', function () {
    [$console, , $factor] = Mfa::signedIn();
    $new = Mfa::text($console->post('/api/v1/mfa/authenticator', proof($factor['secret']))->assertOk()->json('secret'));
    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => Totp::next($new)])->assertNoContent();

    $old = new Console;
    $old->login('ada@example.org', Identity::PASSWORD)->assertStatus(202);
    $old->post('/api/v1/mfa/challenge', ['code' => Totp::next($factor['secret'])])->assertUnprocessable();
    $old->me()->assertUnauthorized();

    $fresh = new Console;
    $fresh->loginWithMfa('ada@example.org', Identity::PASSWORD, $new)->assertOk();
});

it('ends every OTHER session when the authenticator is replaced, and keeps (and rotates) this one', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $elsewhere = new Console;
    $elsewhere->loginWithMfa('ada@example.org', Identity::PASSWORD, $factor['secret'])->assertOk();
    $new = Mfa::text($console->post('/api/v1/mfa/authenticator', proof($factor['secret']))->assertOk()->json('secret'));
    $before = $console->cookieValues();

    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => Totp::next($new)])->assertNoContent();

    $elsewhere->me()->assertUnauthorized();
    $console->me()->assertOk();
    Console::replaying($before)->me()->assertUnauthorized();   // the id used before is dead
    expect(Identity::context(Identity::events('mfa.replaced')[0]))->toBe(['signed_out' => 1]);
});

it('leaves the recovery codes alone when the authenticator is replaced', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $new = Mfa::text($console->post('/api/v1/mfa/authenticator', proof($factor['secret']))->assertOk()->json('secret'));
    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => Totp::next($new)])->assertNoContent();

    expect(Mfa::remainingCodes($account))->toBe(10);
    $next = new Console;
    $next->login('ada@example.org', Identity::PASSWORD)->assertStatus(202);
    $next->challengeWithRecoveryCode($factor['codes'][0])->assertOk();
});

it('refuses to start a replacement without fresh proof', function () {
    [$console, $account, $factor] = Mfa::signedIn();

    $console->post('/api/v1/mfa/authenticator', [])->assertUnprocessable();
    $console->post('/api/v1/mfa/authenticator', ['current_password' => 'not it', 'code' => Totp::next($factor['secret'])])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $console->post('/api/v1/mfa/authenticator', ['current_password' => Identity::PASSWORD, 'code' => '000000'])->assertUnprocessable()->assertJsonValidationErrors('code');

    expect(Mfa::factorRow($account)?->pending_secret_ciphertext)->toBeNull();
});

it('will not confirm a replacement that was never started, or that has gone stale', function () {
    [$console, , $factor] = Mfa::signedIn();

    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => Totp::next($factor['secret'])])
        ->assertUnprocessable()->assertJsonPath('errors.authenticator.0', 'There is no authenticator setup in progress. Start again.');

    $new = Mfa::text($console->post('/api/v1/mfa/authenticator', proof($factor['secret']))->assertOk()->json('secret'));
    Console::advance(16 * 60);   // 15 minutes is the most a pending secret may wait
    $console->me()->assertOk();   // (still signed in: activity within 30 minutes)
    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => Totp::next($new)])->assertUnprocessable()->assertJsonValidationErrors('authenticator');
});

it('lets a replacement be restarted, and only the latest secret can confirm it', function () {
    [$console, , $factor] = Mfa::signedIn();
    $first = Mfa::text($console->post('/api/v1/mfa/authenticator', proof($factor['secret']))->assertOk()->json('secret'));
    $second = Mfa::text($console->post('/api/v1/mfa/authenticator', proof($factor['secret']))->assertOk()->json('secret'));

    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => Totp::next($first)])->assertUnprocessable();
    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => Totp::next($second)])->assertNoContent();
});

it('does not expose the secret in any later response, or in the audit trail', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $begun = $console->post('/api/v1/mfa/authenticator', proof($factor['secret']))->assertOk();
    $new = Mfa::text($begun->json('secret'));
    $uri = Mfa::text($begun->json('otpauth_uri'));
    $code = Totp::next($new);
    $console->post('/api/v1/mfa/authenticator/confirm', ['code' => $code])->assertNoContent();

    foreach ([$console->me(), $console->get('/api/v1/zz/actor')] as $response) {
        Mfa::assertAbsent((string) $response->getContent(), $new, $factor['secret'], 'otpauth');
    }
    $audit = Mfa::auditText();
    foreach ([$new, $uri, $code, $factor['secret'], Identity::PASSWORD] as $forbidden) {
        expect($audit)->not->toContain($forbidden);
    }
});

it('gives a password-only session no way to replace or regenerate anything', function () {
    $account = Identity::savedActiveAccount();   // holds no role, has no authenticator
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    $console->post('/api/v1/mfa/authenticator', ['current_password' => Identity::PASSWORD, 'code' => '123456'])->assertUnprocessable();
    $console->post('/api/v1/mfa/recovery-codes', ['current_password' => Identity::PASSWORD, 'code' => '123456'])->assertUnprocessable();
    expect(Mfa::factorRow($account))->toBeNull();
});

it('gives no way to switch multi-factor authentication off', function () {
    // The absence of a route is the property: there is no disable, remove, reset or delete endpoint, and
    // nothing under /mfa or /security accepts anything but POST. This is about a person's OWN factor: the
    // administrative reset of ANOTHER account's is a different, capability-guarded route (ADR 0024), pinned below.
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => ! str_starts_with($route->uri(), 'api/v1/admin/'))
        ->filter(fn ($route) => str_contains($route->uri(), '/mfa') || str_contains($route->uri(), '/security'));

    expect($routes)->not->toBeEmpty();
    foreach ($routes as $route) {
        expect($route->uri())->not->toMatch('/disable|remove|reset|off|delete/')
            ->and($route->methods())->toBe(['POST']);
    }

    [$console] = Mfa::signedIn();
    foreach (['/api/v1/mfa/disable', '/api/v1/mfa/authenticator/disable', '/api/v1/mfa/reset'] as $path) {
        $console->post($path)->assertNotFound();
    }

    // The one route that removes a factor is another person's, needs a capability and recent verification, and
    // has no counterpart for the caller's own account.
    $reset = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'mfa/reset'));
    expect($reset->map(fn ($route) => $route->uri())->values()->all())->toBe(['api/v1/admin/accounts/{account}/mfa/reset'])
        ->and($reset->first()?->gatherMiddleware())->toContain('security.verified', 'can:identity.mfa.recover');
});
