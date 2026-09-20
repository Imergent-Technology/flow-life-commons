<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use InvalidArgumentException;

/**
 * The bearer secret in an invitation link: 32 random bytes, base64url encoded.
 *
 * Only its hash is ever stored (`account_invitations.token_hash`); the secret exists
 * in memory long enough to be sent once. A fast SHA-256 is right here, unlike a password
 * hash: the token carries 256 bits of entropy, so there is nothing to brute-force, and
 * a deterministic hash is what lets the invitation be looked up by the presented token.
 */
final readonly class InvitationToken
{
    private const int BYTES = 32;

    private function __construct(private string $secret) {}

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '='));
    }

    /** A token as presented by a client. Rejects anything that could not have been issued. */
    public static function fromPresented(string $presented): self
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $presented) !== 1) {
            throw new InvalidArgumentException('Not a valid invitation token.');
        }

        return new self($presented);
    }

    /** The secret itself, for the one place it must be delivered. Never log or store it. */
    public function reveal(): string
    {
        return $this->secret;
    }

    /** Lowercase hex SHA-256: the only representation that is persisted. */
    public function hash(): string
    {
        return hash('sha256', $this->secret);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['secret' => '[redacted]'];
    }
}
