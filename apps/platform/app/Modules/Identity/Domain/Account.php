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
     * Only an active Account with a credential may establish a session. Invited and
     * disabled Accounts may not: the first has no credential yet, the second is blocked.
     */
    public function canAuthenticate(): bool
    {
        return $this->status === AccountStatus::Active && $this->passwordHash !== null;
    }

    /** Records a successful sign-in. Activity, not a profile change, so updatedAt is untouched. */
    public function recordLogin(DateTimeImmutable $now): self
    {
        if (! $this->canAuthenticate()) {
            throw new InvalidAccountState('Only an account that may authenticate can record a login.');
        }

        return new self(
            $this->id, $this->personId, $this->email, $this->status, $this->passwordHash,
            $this->passwordUpdatedAt, $this->emailVerifiedAt, $this->disabledAt, $now,
            $this->createdAt, $this->updatedAt,
        );
    }

    /**
     * Invitation acceptance: the holder chose a password and the Account becomes active.
     * `$passwordHash` is an already computed hash; the domain never sees a plain password.
     *
     * Activation leaves `emailVerifiedAt` as it was (null for an invited Account). That field means
     * ONE thing: the platform has evidence the holder demonstrated control of the mailbox. Choosing a
     * password does not show that, and neither does holding an invitation token, which an operator may
     * have handed over (the administrator bootstrap). Only an invitation the platform MAILED to the
     * address shows it, and the acceptance that knows so records it with its own explicit operation,
     * verifyEmail(), rather than a flag on this one (ADR 0024).
     */
    public function activate(string $passwordHash, DateTimeImmutable $now): self
    {
        if ($this->status !== AccountStatus::Invited) {
            throw new InvalidAccountState(sprintf('Only an invited account can be activated, not a %s one.', $this->status->value));
        }

        return new self(
            $this->id, $this->personId, $this->email, AccountStatus::Active,
            $passwordHash, $now, $this->emailVerifiedAt, null,
            $this->lastLoginAt, $this->createdAt, $now,
        );
    }

    /**
     * Records evidence that the holder controls the mailbox: what `emailVerifiedAt` means and the only thing it
     * means. Nothing else calls this: the one operation that has such evidence is accepting an invitation the
     * platform mailed to the address. The earliest evidence stands, so calling it again changes nothing.
     */
    public function verifyEmail(DateTimeImmutable $now): self
    {
        if ($this->emailVerifiedAt !== null) {
            return $this;
        }

        return new self(
            $this->id, $this->personId, $this->email, $this->status, $this->passwordHash, $this->passwordUpdatedAt,
            $now, $this->disabledAt, $this->lastLoginAt, $this->createdAt, $now,
        );
    }

    /**
     * Replaces the credential of an Account that can sign in (a reset or an authenticated change).
     * Only an ACTIVE Account with a credential qualifies: a reset must never activate an invited
     * Account or re-enable a disabled one, so both refuse here whatever the caller intended.
     * `$passwordHash` is an already computed hash; the domain never sees a plain password.
     */
    public function changePassword(string $passwordHash, DateTimeImmutable $now): self
    {
        if (! $this->canAuthenticate()) {
            throw new InvalidAccountState('Only an active account with a credential can change its password.');
        }

        return new self(
            $this->id, $this->personId, $this->email, AccountStatus::Active,
            $passwordHash, $now, $this->emailVerifiedAt, null, $this->lastLoginAt, $this->createdAt, $now,
        );
    }

    /**
     * Puts a disabled Account back where it was before it was disabled: ACTIVE if it ever chose a password, and
     * INVITED if it never did (a disable can happen before the invitation is accepted). Nothing else changes:
     * the credential, the Person, the assignments and the history are as they were, and no session is created.
     * Whether the Account can then sign in is the ordinary question, answered the ordinary way.
     */
    public function enable(DateTimeImmutable $now): self
    {
        if ($this->status !== AccountStatus::Disabled) {
            throw new InvalidAccountState(sprintf('Only a disabled account can be enabled, not a %s one.', $this->status->value));
        }

        return new self(
            $this->id, $this->personId, $this->email,
            $this->passwordHash === null ? AccountStatus::Invited : AccountStatus::Active,
            $this->passwordHash, $this->passwordUpdatedAt, $this->emailVerifiedAt, null,
            $this->lastLoginAt, $this->createdAt, $now,
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
