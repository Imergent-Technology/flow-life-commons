<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * The shared secret behind an Account's authenticator app, as base32 text (RFC 4648), the form every
 * authenticator app accepts.
 *
 * It is a SECRET, and the one kind that cannot be hashed: the server must compute codes from it. So
 * it exists in plain text only briefly, in memory: while it is shown once at enrolment, and while a
 * code is being checked. At rest it is encrypted (see TotpSecretCipher), and this value is never
 * logged, audited or returned after enrolment has been confirmed.
 */
final readonly class TotpSecret
{
    /** @param  non-empty-string  $base32 */
    private function __construct(private string $base32) {}

    /**
     * @throws InvalidTotpSecret not base32, or too short to be a real secret
     */
    public static function fromBase32(#[\SensitiveParameter] string $base32): self
    {
        // 16 base32 characters is the 80 bits RFC 4226 calls the floor; the library generates 160.
        if (preg_match('/^[A-Z2-7]{16,128}$/D', $base32) !== 1) {
            throw new InvalidTotpSecret('A TOTP secret is 16 to 128 upper-case base32 characters.');
        }

        return new self($base32);
    }

    /**
     * The secret itself, for the two places that must use it: showing it once, and checking a code.
     *
     * @return non-empty-string
     */
    public function reveal(): string
    {
        return $this->base32;
    }

    /** @return array<string, string> a secret must not reach a dump, a log or an exception trace by accident */
    public function __debugInfo(): array
    {
        return ['base32' => '[redacted]'];
    }
}
