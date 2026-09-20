<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Mfa;

use App\Modules\Identity\Application\CredentialMarker;
use App\Modules\Identity\Domain\Account;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * HMAC-SHA256 of the stored password hash, keyed from the application key with a purpose label. It lives
 * in a browser session for a few minutes at most, so a key rotation costs nothing but an unfinished
 * sign-in. It reveals nothing about the password: the hash it is computed from is a salted bcrypt hash,
 * and the result cannot be used to verify a guess or to recover either.
 */
final readonly class HmacCredentialMarker implements CredentialMarker
{
    public function __construct(private Config $config) {}

    public function for(Account $account): string
    {
        $key = hash_hmac('sha256', 'flowlife:credential-marker:v1', $this->config->string('app.key'), true);

        return hash_hmac('sha256', (string) $account->passwordHash, $key);
    }
}
