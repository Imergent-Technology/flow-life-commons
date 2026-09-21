<?php

declare(strict_types=1);

use App\Modules\Identity\Application\TotpSecretCipher;
use App\Modules\Identity\Application\TotpSecretUnreadable;
use App\Modules\Identity\Domain\TotpSecret;
use Illuminate\Encryption\Encrypter;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Support\Facades\DB;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * Rotating APP_KEY (docs/runbooks/app-key-rotation.md).
 *
 * TOTP secrets are the one thing in this platform whose recoverability depends on the application key
 * (ADR 0023). Rotating it without carrying the old key forward makes every enrolled authenticator
 * unreadable and locks out everyone who has one — a lockout that no administrator can undo without the
 * old key, because the ciphertext is all there is.
 *
 * So the runbook's central claim, "old ciphertext keeps working while the old key is in
 * APP_PREVIOUS_KEYS", is proved here rather than asserted there, together with the two facts that make
 * the procedure safe to finish: new ciphertext really is written under the NEW key, and dropping the old
 * key really does break the old ciphertext — loudly, and never as "your code was wrong".
 *
 * Nothing here rotates the development key. Each case builds its own keys and its own encrypter.
 */

/** A fresh application key, in the form the framework writes into an environment file. */
function generatedKey(): string
{
    return 'base64:'.base64_encode(Encrypter::generateKey(config()->string('app.cipher')));
}

/** Runs the application with this key, and these previous keys, as a deployment would be configured. */
function withKeys(string $current, string ...$previous): void
{
    config(['app.key' => $current, 'app.previous_keys' => array_values($previous)]);
    app()->forgetInstance('encrypter');
    (new EncryptionServiceProvider(app()))->register();
}

$secret = Totp::SECRET;

afterEach(function () {
    // Put the suite's own key back, so a later test is not run under a rotated one.
    app()->forgetInstance('encrypter');
    (new EncryptionServiceProvider(app()))->register();
});

it('still decrypts a secret encrypted under the old key while that key is a previous key', function () use ($secret) {
    // Step 5 of the runbook, and the reason the whole procedure is safe.
    $old = generatedKey();
    withKeys($old);
    $ciphertext = app(TotpSecretCipher::class)->encrypt(TotpSecret::fromBase32($secret));

    $new = generatedKey();
    withKeys($new, $old);

    expect(app(TotpSecretCipher::class)->decrypt($ciphertext)->reveal())->toBe($secret);
});

it('writes new ciphertext under the CURRENT key, not a previous one', function () use ($secret) {
    // Step 6. Without this, "rotation" would leave everything still depending on the key being retired.
    $old = generatedKey();
    $new = generatedKey();
    withKeys($new, $old);
    $ciphertext = app(TotpSecretCipher::class)->encrypt(TotpSecret::fromBase32($secret));

    // Now take the old key away entirely. If the value had been written under it, this would fail.
    withKeys($new);

    expect(app(TotpSecretCipher::class)->decrypt($ciphertext)->reveal())->toBe($secret);
});

it('makes old ciphertext unreadable once the old key is retired', function () use ($secret) {
    // Step 7, and the cost of getting it wrong. This is why the runbook says to retire a key only once
    // nothing still depends on it, and how an operator can tell the difference.
    $old = generatedKey();
    withKeys($old);
    $ciphertext = app(TotpSecretCipher::class)->encrypt(TotpSecret::fromBase32($secret));

    withKeys(generatedKey());

    expect(fn () => app(TotpSecretCipher::class)->decrypt($ciphertext))
        ->toThrow(TotpSecretUnreadable::class);
});

it('signs everyone out when the key changes with no previous key kept', function () {
    // Worth knowing before starting a rotation, and the first thing an operator will see: the session
    // cookie and the request-forgery token are encrypted under APP_KEY too. With the old key in
    // APP_PREVIOUS_KEYS they keep working; without it every signed-in person is signed out at once.
    [$console] = Mfa::signedIn();
    $console->me()->assertOk();

    withKeys(generatedKey());

    $console->me()->assertUnauthorized();
});

it('reports an unreadable secret as a fault, never as a wrong code', function () use ($secret) {
    // The operationally important half. If a botched rotation showed as "that code is not valid", an
    // operator would spend the outage looking at clocks and authenticator apps instead of at APP_KEY,
    // and the affected people would be told they had made a mistake.
    $account = Mfa::guardian();
    Mfa::enroll($account, $secret);

    // The key changes, with no previous key kept: the botched rotation. The sign-in that follows is a
    // fresh one, as a person's would be after being signed out by it.
    withKeys(generatedKey());

    $console = new Console;
    $console->login($account->email->value, Identity::PASSWORD)->assertStatus(202);
    $response = $console->post(Console::API.'/mfa/challenge', ['code' => Totp::next($secret)]);

    expect($response->status())->not->toBe(422, 'an unreadable secret was reported as a wrong code')
        ->and($response->status())->toBeGreaterThanOrEqual(500);
    $console->me()->assertUnauthorized();
});

it('leaves recovery codes working after a key change, because they are digests', function () use ($secret) {
    // The one piece of good news in a bad rotation, and worth knowing under pressure: recovery codes are
    // SHA-256 digests bound to the Account and do not depend on APP_KEY, so someone locked out of their
    // authenticator by a lost key can still sign in with one — and then enrol again.
    $account = Mfa::guardian();
    $factor = Mfa::enroll($account, $secret);

    withKeys(generatedKey());

    $console = new Console;
    $console->login($account->email->value, Identity::PASSWORD)->assertStatus(202);
    $console->challengeWithRecoveryCode($factor['codes'][0])->assertOk();
});

it('does not depend on the application key for any other stored secret', function () {
    // The runbook has to name everything a rotation touches. Recovery codes and invitation and reset
    // tokens are hashes, not ciphertext; the password is a bcrypt hash. What the key does protect,
    // besides TOTP secrets, is transient: cookies, the session payload and the credential marker that
    // binds a half-finished sign-in, all of which cost at most an unfinished sign-in.
    $account = Mfa::guardian();
    $factor = Mfa::enroll($account);
    Identity::savedInvitation(Identity::savedInvitedAccount('invited@example.org'));

    $encrypted = static fn (string $table, string $column): int => (int) DB::table($table)
        ->where($column, 'like', 'eyJpdiI6%') // the framework's ciphertext is base64 JSON starting {"iv":
        ->count();

    expect($encrypted('accounts', 'password_hash'))->toBe(0)
        ->and($encrypted('account_recovery_codes', 'code_hash'))->toBe(0)
        ->and($encrypted('account_invitations', 'token_hash'))->toBe(0)
        ->and($encrypted('account_totp_factors', 'secret_ciphertext'))->toBe(1) // the positive control
        ->and($factor['codes'])->toHaveCount(10);
});
