<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\InvitationToken;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * The outcome of inviting someone: who now exists, and the ONE-TIME secret that lets them finish
 * setting up their Account.
 *
 * The secret is shown to nobody until the transaction that created the invitation has committed,
 * which is why it is only reachable through reveal(), and why the caller must hold this object
 * until then. Only its hash is ever stored. It is not recoverable afterwards, and it never appears
 * in audit events, logs or a debug dump of this object.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public PersonId $personId,
        public AccountId $accountId,
        public string $email,
        public DateTimeImmutable $expiresAt,
        private InvitationToken $token,
    ) {}

    /** The bearer secret, for delivery to the invitee. Never log or store it. */
    public function revealToken(): string
    {
        return $this->token->reveal();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['personId' => $this->personId->value, 'accountId' => $this->accountId->value, 'token' => '[redacted]'];
    }
}
