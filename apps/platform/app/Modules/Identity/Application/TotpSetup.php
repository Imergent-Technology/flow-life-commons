<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\TotpSecret;

/**
 * What a person needs to add an authenticator: the secret (as a manual key) and the provisioning URI
 * (for a QR code). Shown ONCE, when it is generated. Nothing here is retrievable afterwards, because the
 * server keeps only the encrypted secret and offers no way to read it back.
 */
final readonly class TotpSetup
{
    public function __construct(
        public TotpSecret $secret,
        public string $provisioningUri,
    ) {}
}
