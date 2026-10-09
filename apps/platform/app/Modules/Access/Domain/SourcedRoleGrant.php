<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A role granted because of one specific source instance (ADR 0038, K4).
 *
 * A row's existence means the grant is active. Withdrawal deletes it. The history is the
 * audit trail. This is not an independent assignment: `role_assignments` is untouched, and
 * revoking an independent assignment never removes a row here.
 *
 * `sourceId` has no foreign key. Access does not know whether the source still exists;
 * the module that owns the source keeps the two consistent in one transaction.
 */
final readonly class SourcedRoleGrant
{
    private function __construct(
        public SourcedRoleGrantId $id,
        public PersonId $personId,
        public string $roleKey,
        public string $sourceType,
        public string $sourceId,
        public ?AccountId $grantedByAccountId,
        public DateTimeImmutable $grantedAt,
    ) {}

    public static function grant(
        PersonId $personId,
        string $roleKey,
        string $sourceType,
        string $sourceId,
        ?AccountId $grantedBy,
        DateTimeImmutable $now,
    ): self {
        if (preg_match(RoleAssignment::KEY_SHAPE, $roleKey) !== 1) {
            throw new InvalidArgumentException('A role key is lowercase letters, digits, underscores and hyphens, up to 64 characters, and starts with a letter.');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $sourceType) !== 1) {
            throw new InvalidArgumentException('A grant source type is lowercase snake_case, up to 32 characters.');
        }
        try {
            $canonicalSource = SourcedRoleGrantId::fromString($sourceId)->value;
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('A grant source id is a ULID.');
        }

        return new self(
            SourcedRoleGrantId::generate(),
            $personId,
            $roleKey,
            $sourceType,
            $canonicalSource,
            $grantedBy,
            $now,
        );
    }

    /**
     * Rebuilds a stored row without validating the key. An obsolete key must stay readable
     * so authorization can ignore it, the same way an obsolete assignment does.
     */
    public static function reconstitute(
        SourcedRoleGrantId $id,
        PersonId $personId,
        string $roleKey,
        string $sourceType,
        string $sourceId,
        ?AccountId $grantedBy,
        DateTimeImmutable $grantedAt,
    ): self {
        return new self($id, $personId, $roleKey, $sourceType, $sourceId, $grantedBy, $grantedAt);
    }
}
