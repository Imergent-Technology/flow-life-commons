<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpSecret;
use DateTimeImmutable;

/**
 * What a person needs to add an authenticator: the secret (as a manual key), the provisioning URI (for a
 * QR code), and until when the pending secret can still be proved. Shown ONCE, when it is generated.
 * Nothing here is retrievable afterwards, because the server keeps only the encrypted secret and offers no
 * way to read it back.
 */
final readonly class TotpSetup
{
    private function __construct(
        public TotpSecret $secret,
        public string $provisioningUri,
        /** The last moment the pending secret can be confirmed: the server's answer, never the client's to work out. */
        public DateTimeImmutable $expiresAt,
    ) {}

    /** A secret that was made pending at `$startedAt`. Its lifetime is the Domain's (`hasFreshPending` enforces it). */
    public static function pending(TotpSecret $secret, string $provisioningUri, DateTimeImmutable $startedAt): self
    {
        return new self($secret, $provisioningUri, $startedAt->modify('+'.TotpFactor::PENDING_LIFETIME_SECONDS.' seconds'));
    }
}
