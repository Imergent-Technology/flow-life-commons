<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\AccountId;
use DateTimeImmutable;

/**
 * An Account's authenticator (TOTP) enrolment. At most one per Account.
 *
 * It holds ENCRYPTED secrets only: the ciphertext is opaque to the domain, which never sees a key.
 *
 * Two secrets can exist, and only one is ever live:
 *
 * - the ACTIVE secret, which codes are checked against at sign-in. It exists only once the person has
 *   proved possession of it with a valid code. `enrolledAt` is set at that moment and not before.
 * - a PENDING secret, generated but not yet proved. It is either a first enrolment (no active secret
 *   yet) or a replacement (the active one still works). It changes nothing until confirmed, and
 *   abandoning it strands no one: the active secret, if any, is untouched, and starting again simply
 *   replaces the pending one.
 *
 * `lastUsedStep` is the last TOTP time step accepted, so the same code cannot be replayed within its
 * validity window.
 */
final readonly class TotpFactor
{
    /** How long a generated secret may wait to be proved before it is too stale to confirm. */
    public const int PENDING_LIFETIME_SECONDS = 900;

    private function __construct(
        public TotpFactorId $id,
        public AccountId $accountId,
        public ?string $secretCiphertext,
        public ?string $pendingCiphertext,
        public ?DateTimeImmutable $pendingStartedAt,
        public ?DateTimeImmutable $enrolledAt,
        public ?int $lastUsedStep,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (($pendingCiphertext === null) !== ($pendingStartedAt === null)) {
            throw new InvalidAccountState('A pending secret and the time it was started exist together or not at all.');
        }
        if (($secretCiphertext === null) !== ($enrolledAt === null)) {
            throw new InvalidAccountState('An active secret and the time it was enrolled exist together or not at all.');
        }
        if ($secretCiphertext === null && $pendingCiphertext === null) {
            throw new InvalidAccountState('An enrolment holds an active secret, a pending one, or both.');
        }
    }

    /** A first enrolment: a pending secret and nothing active. */
    public static function begin(TotpFactorId $id, AccountId $accountId, string $pendingCiphertext, DateTimeImmutable $now): self
    {
        return new self($id, $accountId, null, $pendingCiphertext, $now, null, null, $now, $now);
    }

    public static function reconstitute(
        TotpFactorId $id,
        AccountId $accountId,
        ?string $secretCiphertext,
        ?string $pendingCiphertext,
        ?DateTimeImmutable $pendingStartedAt,
        ?DateTimeImmutable $enrolledAt,
        ?int $lastUsedStep,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $accountId, $secretCiphertext, $pendingCiphertext, $pendingStartedAt, $enrolledAt, $lastUsedStep, $createdAt, $updatedAt);
    }

    /** Whether an authenticator has been proved, so a second factor is checked at sign-in. */
    public function isActive(): bool
    {
        return $this->secretCiphertext !== null;
    }

    /** Whether a pending secret exists and was started no longer than `$ttlSeconds` ago. */
    public function hasFreshPending(DateTimeImmutable $now, int $ttlSeconds): bool
    {
        return $this->pendingStartedAt !== null
            && $this->pendingCiphertext !== null
            && $now->getTimestamp() - $this->pendingStartedAt->getTimestamp() <= $ttlSeconds;
    }

    /** Replaces any earlier pending secret. The active secret, if there is one, is untouched. */
    public function withPending(string $pendingCiphertext, DateTimeImmutable $now): self
    {
        return new self(
            $this->id, $this->accountId, $this->secretCiphertext, $pendingCiphertext, $now,
            $this->enrolledAt, $this->lastUsedStep, $this->createdAt, $now,
        );
    }

    /**
     * The pending secret was proved with a valid code (at `$step`): it becomes the active one.
     * Whatever was active before stops working. `enrolledAt` keeps its first value.
     */
    public function confirmPending(int $step, DateTimeImmutable $now): self
    {
        if ($this->pendingCiphertext === null) {
            throw new InvalidAccountState('There is no pending secret to confirm.');
        }

        return new self(
            $this->id, $this->accountId, $this->pendingCiphertext, null, null,
            $this->enrolledAt ?? $now, $step, $this->createdAt, $now,
        );
    }

    /** A code for the active secret was accepted at `$step`; it cannot be accepted again. */
    public function withStepUsed(int $step, DateTimeImmutable $now): self
    {
        return new self(
            $this->id, $this->accountId, $this->secretCiphertext, $this->pendingCiphertext, $this->pendingStartedAt,
            $this->enrolledAt, $step, $this->createdAt, $now,
        );
    }
}
