<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\TotpSecret;
use DateTimeImmutable;

/**
 * Everything the platform needs from a TOTP (RFC 6238) implementation, and nothing more. A port, so
 * Identity depends on no library: the one adapter (Infrastructure) wraps a maintained one, and swapping it
 * changes nothing here. The platform writes no TOTP cryptography of its own.
 */
interface TotpAuthenticator
{
    /** A fresh random secret with at least RFC 4226's 160 bits. */
    public function generateSecret(): TotpSecret;

    /**
     * The `otpauth://` provisioning URI an authenticator app reads from a QR code. It CONTAINS the
     * secret. It is shown once during enrolment, rendered to a QR code in the browser, and never sent
     * to any third party (in particular, never to a QR-code web service).
     */
    public function provisioningUri(TotpSecret $secret, string $accountLabel): string;

    /**
     * The time step at which `$code` is a valid code for `$secret`, or null.
     *
     * Only the current step and one either side are accepted (a 30-second step, so about a minute and
     * a half in total: enough for clock drift, no wider), and only a step STRICTLY LATER than
     * `$afterStep`, so a code that has been used cannot be used again.
     */
    public function matchingStep(TotpSecret $secret, string $code, DateTimeImmutable $at, ?int $afterStep): ?int;
}
