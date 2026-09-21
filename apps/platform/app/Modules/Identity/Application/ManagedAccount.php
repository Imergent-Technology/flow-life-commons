<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * What an operator needs to see about an Account to administer it, and nothing else: who it is, what state it is
 * in, and whether it can prove a second factor. Deliberately NOT a contact record.
 *
 * It holds no credential, hash, secret, token, session, reset state or audit content. Roles are Access's, so they
 * are not here: Access adds them to what it presents.
 *
 * `invitationExpiresAt` and `invitationChannel` describe the Account's outstanding (never accepted) invitation, and
 * are null once there is none.
 */
final readonly class ManagedAccount
{
    public function __construct(
        public AccountId $id,
        public PersonId $personId,
        public string $displayName,
        public string $email,
        public ?DateTimeImmutable $emailVerifiedAt,
        public string $status,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastLoginAt,
        public ?DateTimeImmutable $disabledAt,
        public bool $mfaEnrolled,
        public int $recoveryCodesRemaining,
        public ?DateTimeImmutable $invitationExpiresAt,
        public ?string $invitationChannel,
    ) {}
}
