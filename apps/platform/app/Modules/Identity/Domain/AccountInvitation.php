<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\AccountId;
use DateTimeImmutable;

/**
 * The right to finish setting up an invited Account by presenting a secret.
 *
 * - Only a hash of the token is held; the raw secret is passed in once at issue()
 *   and never retained, so it cannot leak from this object.
 * - Expiry is an instant: usable strictly before `expiresAt`, expired at and after it.
 * - One-time use is `acceptedAt`. Revocation is deleting the row, so there is no
 *   revoked state to model.
 * - `invitedByAccountId` is provenance, not a relationship: it has no foreign key
 *   (ADR 0021) and is null when the platform itself issues the invitation, such as the
 *   administrator bootstrap command.
 * - `channel` is how the token reaches its holder (ADR 0024). It is fixed when the invitation is issued:
 *   an EMAIL invitation was mailed to the Account's own address, so accepting it shows mailbox control;
 *   an OPERATOR one was handed over, so it does not.
 */
final readonly class AccountInvitation
{
    private function __construct(
        public AccountInvitationId $id,
        public AccountId $accountId,
        public string $tokenHash,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $acceptedAt,
        public ?AccountId $invitedByAccountId,
        public InvitationChannel $channel = InvitationChannel::Operator,
    ) {}

    public static function issue(
        AccountInvitationId $id,
        AccountId $accountId,
        InvitationToken $token,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
        ?AccountId $invitedByAccountId = null,
        InvitationChannel $channel = InvitationChannel::Operator,
    ): self {
        if ($expiresAt <= $now) {
            throw new InvitationNotUsable('An invitation must expire after it is issued.');
        }

        return new self($id, $accountId, $token->hash(), $expiresAt, null, $invitedByAccountId, $channel);
    }

    public static function reconstitute(
        AccountInvitationId $id,
        AccountId $accountId,
        string $tokenHash,
        DateTimeImmutable $expiresAt,
        ?DateTimeImmutable $acceptedAt,
        ?AccountId $invitedByAccountId,
        InvitationChannel $channel = InvitationChannel::Operator,
    ): self {
        return new self($id, $accountId, $tokenHash, $expiresAt, $acceptedAt, $invitedByAccountId, $channel);
    }

    public function isAccepted(): bool
    {
        return $this->acceptedAt !== null;
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isUsableAt(DateTimeImmutable $now): bool
    {
        return ! $this->isAccepted() && ! $this->isExpiredAt($now);
    }

    public function accept(DateTimeImmutable $now): self
    {
        if ($this->isAccepted()) {
            throw new InvitationNotUsable('The invitation has already been accepted.');
        }
        if ($this->isExpiredAt($now)) {
            throw new InvitationNotUsable('The invitation has expired.');
        }

        return new self($this->id, $this->accountId, $this->tokenHash, $this->expiresAt, $now, $this->invitedByAccountId, $this->channel);
    }
}
