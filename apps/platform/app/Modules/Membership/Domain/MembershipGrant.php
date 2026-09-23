<?php

declare(strict_types=1);

namespace App\Modules\Membership\Domain;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A time-bounded grant of membership access to a Person (ADR 0028).
 *
 * - **Non-deleting history with explicit one-way revocation.** After creation, every property
 *   except `revokedAt`/`revokedByAccountId` is immutable, and a grant is never deleted. Those
 *   two are set together, exactly once, by the repository's race-safe conditional update —
 *   never by loading this object, mutating it and saving it back, which is why it has no
 *   `revoke()` method: there is nothing for one to return that persistence would use.
 * - **Intervals are half-open** `[startsAt, endsAt)`: active AT `startsAt`, inactive AT
 *   `endsAt`. `endsAt === null` is an explicit, approved open-ended grant (honorary, founding,
 *   legacy or other indefinite access), not "no end date entered yet".
 * - **`source`/`sourceReference` are provenance only**: an opaque handle for a human
 *   investigating a grant, never parsed, never Person identity, and never unique — a refund,
 *   correction or regrant may legitimately cite the same business reference twice (ADR 0029).
 * - **`grantedByAccountId`/`revokedByAccountId` are provenance too**, with no foreign key
 *   (ADR 0021): they record what happened and must neither block an operation nor be broken
 *   by one.
 */
final readonly class MembershipGrant
{
    public const int MAX_SOURCE_REFERENCE_LENGTH = 191;

    private function __construct(
        public MembershipGrantId $id,
        public PersonId $personId,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public MembershipGrantSource $source,
        public ?string $sourceReference,
        public ?AccountId $grantedByAccountId,
        public ?DateTimeImmutable $revokedAt,
        public ?AccountId $revokedByAccountId,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * A new, unrevoked grant. The term must be a positive interval: open-ended, or strictly
     * after `startsAt`. A zero-length or backwards interval is refused, not silently corrected.
     *
     * @throws InvalidMembershipTerm `endsAt` is not strictly after `startsAt`
     * @throws InvalidArgumentException `sourceReference` is longer than MAX_SOURCE_REFERENCE_LENGTH
     */
    public static function grant(
        MembershipGrantId $id,
        PersonId $personId,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        MembershipGrantSource $source,
        ?string $sourceReference,
        ?AccountId $grantedByAccountId,
        DateTimeImmutable $now,
    ): self {
        if ($endsAt !== null && $endsAt <= $startsAt) {
            throw new InvalidMembershipTerm('A membership grant must end strictly after it starts, or not end at all.');
        }
        if ($sourceReference !== null && mb_strlen($sourceReference) > self::MAX_SOURCE_REFERENCE_LENGTH) {
            throw new InvalidArgumentException(sprintf('A source reference is at most %d characters.', self::MAX_SOURCE_REFERENCE_LENGTH));
        }

        return new self($id, $personId, $startsAt, $endsAt, $source, $sourceReference, $grantedByAccountId, null, null, $now);
    }

    /**
     * Rebuilds a stored row, revoked or not. Deliberately NOT re-validated: a row already
     * committed is read back as it is, so a corrupt or historical row remains readable.
     */
    public static function reconstitute(
        MembershipGrantId $id,
        PersonId $personId,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        MembershipGrantSource $source,
        ?string $sourceReference,
        ?AccountId $grantedByAccountId,
        ?DateTimeImmutable $revokedAt,
        ?AccountId $revokedByAccountId,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $personId, $startsAt, $endsAt, $source, $sourceReference, $grantedByAccountId, $revokedAt, $revokedByAccountId, $createdAt);
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }
}
