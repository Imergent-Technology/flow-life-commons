<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Mfa;

use App\Modules\Identity\Application\TotpAuthenticator;
use App\Modules\Identity\Domain\TotpSecret;
use DateTimeImmutable;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;

/**
 * The TOTP adapter: spomky-labs/otphp (RFC 4226 / 6238), the only place that library is named. The
 * platform writes no TOTP cryptography of its own: the HMAC and code derivation are the library's.
 *
 * Parameters are the interoperable defaults every authenticator app supports: 6 digits, a 30-second
 * step, HMAC-SHA1. They are fixed in code, not configuration: they are a compatibility contract with
 * apps people already hold, and changing one would silently invalidate every enrolment.
 *
 * NEVER call the library's `getQrCodeUri()`: it builds a URL for an external QR-code web service, which
 * would disclose the secret. The QR code is rendered in the browser from the provisioning URI.
 */
final readonly class OtphpTotpAuthenticator implements TotpAuthenticator
{
    private const int PERIOD_SECONDS = 30;

    /** Steps accepted either side of the current one: about a minute and a half in all. */
    private const int WINDOW_STEPS = 1;

    /** RFC 4226 recommends 160 bits; the library's own default is 512, which makes an unwieldy manual key. */
    private const int SECRET_BYTES = 20;

    public function __construct(private string $issuer) {}

    public function generateSecret(): TotpSecret
    {
        return TotpSecret::fromBase32(Base32::encodeUpperUnpadded(random_bytes(self::SECRET_BYTES)));
    }

    public function provisioningUri(TotpSecret $secret, string $accountLabel): string
    {
        assert($accountLabel !== '' && $this->issuer !== '');
        $totp = TOTP::createFromSecret($secret->reveal());
        $totp->setLabel($accountLabel);
        $totp->setIssuer($this->issuer);

        return $totp->getProvisioningUri();
    }

    public function matchingStep(TotpSecret $secret, #[\SensitiveParameter] string $code, DateTimeImmutable $at, ?int $afterStep): ?int
    {
        $totp = TOTP::createFromSecret($secret->reveal());
        $current = intdiv($at->getTimestamp(), self::PERIOD_SECONDS);

        // Every step in the window is computed and compared whatever happens, so timing does not say which.
        $matched = null;
        for ($step = $current - self::WINDOW_STEPS; $step <= $current + self::WINDOW_STEPS; $step++) {
            $expected = $step < 0 ? '' : $totp->at($step * self::PERIOD_SECONDS);
            $matches = $expected !== '' && hash_equals($expected, $code);
            if ($matches && ($afterStep === null || $step > $afterStep)) {
                $matched = $step;
            }
        }

        return $matched;
    }
}
