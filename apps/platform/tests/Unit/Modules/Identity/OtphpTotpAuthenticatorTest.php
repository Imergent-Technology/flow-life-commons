<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\TotpSecret;
use App\Modules\Identity\Infrastructure\Mfa\OtphpTotpAuthenticator;
use Tests\Support\Totp;

/*
 * The TOTP adapter, checked against an INDEPENDENT implementation of RFC 6238 (tests/Support/Totp.php) and the
 * RFC's own published vectors, so the platform's code is not verified by the library it wraps.
 */

function authenticator(): OtphpTotpAuthenticator
{
    return new OtphpTotpAuthenticator('Flow Life Test');
}

function rfcSecret(): TotpSecret
{
    return TotpSecret::fromBase32(Totp::RFC_SECRET);
}

it('agrees with the RFC 6238 test vectors (HMAC-SHA1; the low six digits of the published eight)', function (int $time, string $code) {
    // Appendix B, SHA-1 column, "12345678901234567890". Six digits are the last six of the eight-digit vectors.
    expect(Totp::code(Totp::RFC_SECRET, $time))->toBe($code)
        ->and(authenticator()->matchingStep(rfcSecret(), $code, new DateTimeImmutable('@'.$time), null))->toBe(intdiv($time, 30));
})->with([
    'T=59' => [59, '287082'],
    'T=1111111109' => [1111111109, '081804'],
    'T=1111111111' => [1111111111, '050471'],
    'T=1234567890' => [1234567890, '005924'],
    'T=2000000000' => [2000000000, '279037'],
    'T=20000000000' => [20000000000, '353130'],
]);

it('agrees with the independent implementation for random secrets and times', function () {
    for ($i = 0; $i < 25; $i++) {
        $secret = authenticator()->generateSecret();
        $at = random_int(1_700_000_000, 1_900_000_000);

        expect(authenticator()->matchingStep($secret, Totp::code($secret->reveal(), $at), new DateTimeImmutable('@'.$at), null))->toBe(intdiv($at, 30));
    }
});

it('accepts the current step and one either side, and no wider', function () {
    $at = 1_800_000_015;   // middle of a step
    $step = intdiv($at, 30);
    $clock = new DateTimeImmutable('@'.$at);

    foreach ([-1, 0, 1] as $offset) {
        expect(authenticator()->matchingStep(rfcSecret(), Totp::forStep(Totp::RFC_SECRET, $step + $offset), $clock, null))->toBe($step + $offset);
    }
    foreach ([-2, 2, -10, 10] as $offset) {
        expect(authenticator()->matchingStep(rfcSecret(), Totp::forStep(Totp::RFC_SECRET, $step + $offset), $clock, null))->toBeNull();
    }
});

it('accepts a step only once: a step at or before the last one used is refused', function () {
    $at = 1_800_000_015;
    $step = intdiv($at, 30);
    $clock = new DateTimeImmutable('@'.$at);
    $code = Totp::forStep(Totp::RFC_SECRET, $step);

    expect(authenticator()->matchingStep(rfcSecret(), $code, $clock, null))->toBe($step)
        ->and(authenticator()->matchingStep(rfcSecret(), $code, $clock, $step))->toBeNull()          // replay of the same step
        ->and(authenticator()->matchingStep(rfcSecret(), $code, $clock, $step + 1))->toBeNull()      // and anything earlier
        ->and(authenticator()->matchingStep(rfcSecret(), Totp::forStep(Totp::RFC_SECRET, $step + 1), $clock, $step))->toBe($step + 1);
});

it('refuses anything that is not a six-digit code', function (string $code) {
    expect(authenticator()->matchingStep(rfcSecret(), $code, new DateTimeImmutable('@1800000015'), null))->toBeNull();
})->with(['empty' => '', 'short' => '12345', 'long' => '1234567', 'letters' => 'abcdef', 'spaced' => '123 456', 'null bytes' => "12345\0"]);

it('generates a 160-bit base32 secret each time', function () {
    $seen = [];
    for ($i = 0; $i < 50; $i++) {
        $secret = authenticator()->generateSecret()->reveal();
        expect($secret)->toMatch('/^[A-Z2-7]{32}$/D');
        $seen[$secret] = true;
    }

    expect($seen)->toHaveCount(50);
});

it('builds a standard otpauth provisioning URI, and never a link to any service', function () {
    $uri = authenticator()->provisioningUri(rfcSecret(), 'ada@example.org');
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

    expect($uri)->toStartWith('otpauth://totp/')
        ->and($query['secret'])->toBe(Totp::RFC_SECRET)
        ->and($query['issuer'])->toBe('Flow Life Test')
        ->and(rawurldecode($uri))->toContain('ada@example.org')
        // The URI contains the secret, so it must never be a URL that some other server would receive.
        ->and($uri)->not->toContain('http');
});
