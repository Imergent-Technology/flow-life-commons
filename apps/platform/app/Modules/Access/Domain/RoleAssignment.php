<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A currently-active grant of a role to a Person (ADR 0017).
 *
 * - **A row's existence means the grant is active.** There is no revoked state, no
 *   history and no scope: revocation deletes the row, and the history of who held what is
 *   the audit trail's job (ADR 0019), not stale rows here.
 * - The grant is to the PERSON, not the Account: responsibility is human-level and
 *   survives credential changes (ADR 0015).
 * - `roleKey` is a plain string on purpose. The role catalog lives in Access's Application
 *   layer, which a Domain class may not import; keeping the key a string is what keeps
 *   the layers one-way. A key the catalog no longer knows is representable here and
 *   grants nothing there.
 * - `grantedByAccountId` is provenance, not a relationship: no foreign key (ADR 0021), and
 *   null when the platform itself makes the grant (the administrator bootstrap).
 */
final readonly class RoleAssignment
{
    private const string KEY_SHAPE = '/^[a-z][a-z0-9_]{0,63}$/D';

    private function __construct(
        public RoleAssignmentId $id,
        public PersonId $personId,
        public string $roleKey,
        public ?AccountId $grantedByAccountId,
        public DateTimeImmutable $grantedAt,
    ) {}

    /** A new grant. The key must at least be shaped like a role key. */
    public static function grant(PersonId $personId, string $roleKey, ?AccountId $grantedBy, DateTimeImmutable $now): self
    {
        if (preg_match(self::KEY_SHAPE, $roleKey) !== 1) {
            throw new InvalidArgumentException('A role key is lowercase snake_case, up to 64 characters.');
        }

        return new self(RoleAssignmentId::generate(), $personId, $roleKey, $grantedBy, $now);
    }

    /**
     * Rebuilds a stored row. Deliberately NOT validated: a corrupt or obsolete key must be
     * readable so that authorization can ignore it and fail closed, rather than the whole
     * decision failing on it.
     */
    public static function reconstitute(
        RoleAssignmentId $id,
        PersonId $personId,
        string $roleKey,
        ?AccountId $grantedBy,
        DateTimeImmutable $grantedAt,
    ): self {
        return new self($id, $personId, $roleKey, $grantedBy, $grantedAt);
    }
}
