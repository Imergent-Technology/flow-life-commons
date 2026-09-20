<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use DateTimeImmutable;

/**
 * A freshly issued password-reset token and when it stops working. The token is a bearer secret: it
 * exists here only to be delivered to the Account's owner, once, after the transaction that stored its
 * hash has committed. It is not recoverable afterwards and never appears in an audit event, a log or a
 * debug dump of this object.
 */
final readonly class IssuedPasswordReset
{
    public function __construct(
        private string $token,
        public DateTimeImmutable $expiresAt,
    ) {}

    /** The bearer secret, for delivery to the Account's owner. Never log or store it. */
    public function revealToken(): string
    {
        return $this->token;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['token' => '[redacted]', 'expiresAt' => $this->expiresAt->format('c')];
    }
}
