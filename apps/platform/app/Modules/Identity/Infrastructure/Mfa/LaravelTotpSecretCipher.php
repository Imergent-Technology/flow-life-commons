<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Mfa;

use App\Modules\Identity\Application\TotpSecretCipher;
use App\Modules\Identity\Application\TotpSecretUnreadable;
use App\Modules\Identity\Domain\InvalidTotpSecret;
use App\Modules\Identity\Domain\TotpSecret;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * TOTP secrets at rest, through the framework's authenticated encryption under the application key
 * (`APP_KEY`; AES-256 with an integrity check, so a tampered value fails to decrypt rather than yielding
 * garbage). No cryptography is designed here, and none is delegated to the database: the stored value is
 * opaque text and the schema is identical on MariaDB and PostgreSQL.
 *
 * OPERATIONAL DEPENDENCY: every enrolled authenticator depends on the application key. Rotating `APP_KEY`
 * without keeping the old value in `APP_PREVIOUS_KEYS` (which the framework's encrypter consults when
 * decrypting) makes every stored secret unreadable, and everyone with an authenticator unable to sign in.
 * Recovery-code digests do not depend on the key, so they survive a rotation.
 *
 * Nothing here logs the secret or includes it in an exception.
 */
final readonly class LaravelTotpSecretCipher implements TotpSecretCipher
{
    public function __construct(private Encrypter $encrypter) {}

    public function encrypt(TotpSecret $secret): string
    {
        return $this->encrypter->encryptString($secret->reveal());
    }

    public function decrypt(#[\SensitiveParameter] string $ciphertext): TotpSecret
    {
        try {
            return TotpSecret::fromBase32($this->encrypter->decryptString($ciphertext));
        } catch (DecryptException|InvalidTotpSecret) {
            throw new TotpSecretUnreadable('A stored TOTP secret could not be decrypted.');
        }
    }
}
