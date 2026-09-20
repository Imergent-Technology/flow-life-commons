<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\TotpSecret;

/**
 * Encrypts a TOTP secret for storage and decrypts it to check a code. A port so that Identity's Domain
 * and Application never touch the framework's encryption: the adapter (Infrastructure) uses the
 * application's authenticated encryption under the application key. The database stores the opaque
 * result and does no cryptography.
 */
interface TotpSecretCipher
{
    public function encrypt(TotpSecret $secret): string;

    /**
     * @throws TotpSecretUnreadable the stored value cannot be decrypted (a tampered value, or the
     *                              application key it was encrypted under is gone)
     */
    public function decrypt(#[\SensitiveParameter] string $ciphertext): TotpSecret;
}
