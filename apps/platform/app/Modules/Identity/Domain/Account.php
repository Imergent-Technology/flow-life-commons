<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * A Person's means of signing in: login identifier, credential, status. At most one
 * per Person (ADR 0015). It is not the person; the word "User" is avoided on purpose.
 *
 * Accounts are created only by invitation, so the single creation path is invite():
 * status Invited, no credential. Transitions return a new instance.
 *
 * This models state and its invariants only. Authentication, sessions, password reset
 * and the use cases that drive these transitions are later phases of the epic.
 */
final readonly class Account
{
    private function __construct(
        public AccountId $id,
        public PersonId $personId,
        public EmailAddress $email,
        public AccountStatus $status,
        public ?string $passwordHash,
        public ?DateTimeImmutable $passwordUpdatedAt,
        public ?DateTimeImmutable $emailVerifiedAt,
        public ?DateTimeImmutable $disabledAt,
        public ?DateTimeImmutable $lastLoginAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (($passwordHash === null) !== ($passwordUpdatedAt === null)) {
            throw new InvalidAccountState('A password hash and its update time exist together or not at all.');
        }

        $consistent = match ($status) {
            AccountStatus::Invited => $passwordHash === null && $disabledAt === null,
            AccountStatus::Active => $passwordHash !== null && $disabledAt === null,
            AccountStatus::Disabled => $disabledAt !== null,
        };

        if (! $consistent) {
            throw new InvalidAccountState(sprintf(
                'The account state is inconsistent with status "%s" (invited: no credential, not disabled; '
                .'active: credential, not disabled; disabled: has a disabled time).',
                $status->value,
            ));
        }
    }

    public static function invite(AccountId $id, PersonId $personId, EmailAddress $email, DateTimeImmutable $now): self
    {
        return new self($id, $personId, $email, AccountStatus::Invited, null, null, null, null, null, $now, $now);
    }

    public static function reconstitute(
        AccountId $id,
        PersonId $personId,
        EmailAddress $email,
        AccountStatus $status,
        ?string $passwordHash,
        ?DateTimeImmutable $passwordUpdatedAt,
        ?DateTimeImmutable $emailVerifiedAt,
        ?DateTimeImmutable $disabledAt,
        ?DateTimeImmutable $lastLoginAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id, $personId, $email, $status, $passwordHash, $passwordUpdatedAt,
            $emailVerifiedAt, $disabledAt, $lastLoginAt, $createdAt, $updatedAt,
        );
    }

    /**
     * Invitation acceptance: the holder set a password, which also proves they control
     * the address, so no separate verification is needed. `$passwordHash` is an already
     * computed hash; the domain never sees a plain password.
     */
    public function activate(string $passwordHash, DateTimeImmutable $now): self
    {
        if ($this->status !== AccountStatus::Invited) {
            throw new InvalidAccountState(sprintf('Only an invited account can be activated, not a %s one.', $this->status->value));
        }

        return new self(
            $this->id, $this->personId, $this->email, AccountStatus::Active,
            $passwordHash, $now, $now, null, $this->lastLoginAt, $this->createdAt, $now,
        );
    }

    /** Blocks authentication. The Person, their assignments and history are untouched. */
    public function disable(DateTimeImmutable $now): self
    {
        if ($this->status === AccountStatus::Disabled) {
            throw new InvalidAccountState('The account is already disabled.');
        }

        return new self(
            $this->id, $this->personId, $this->email, AccountStatus::Disabled,
            $this->passwordHash, $this->passwordUpdatedAt, $this->emailVerifiedAt, $now,
            $this->lastLoginAt, $this->createdAt, $now,
        );
    }
}
