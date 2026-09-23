<?php

declare(strict_types=1);

namespace App\Modules\Membership\Domain;

use DateTimeImmutable;

/**
 * One continuous span of non-revoked membership coverage: the merge result `MembershipState`
 * builds from a Person's grants. Not persisted and not returned outside `MembershipState`; it
 * exists only to keep the merge arithmetic in named, individually safe operations rather than
 * inline nullable-timestamp comparisons repeated at every call site.
 */
final readonly class MembershipCoverageRun
{
    private function __construct(
        public DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
        public bool $openEnded,
    ) {}

    public static function startingWith(MembershipGrant $grant): self
    {
        return new self($grant->startsAt, $grant->endsAt, $grant->endsAt === null);
    }

    /** Whether a grant starting at $startsAt touches or falls inside this run, so it would merge rather than start a new one. */
    public function touchedOrOverlappedBy(DateTimeImmutable $startsAt): bool
    {
        return $this->openEnded || ($this->end !== null && $this->end >= $startsAt);
    }

    /** This run, extended by a grant whose start already touches or overlaps it (per touchedOrOverlappedBy). */
    public function extendedBy(?DateTimeImmutable $grantEndsAt): self
    {
        if ($this->openEnded || $grantEndsAt === null) {
            return new self($this->start, null, true);
        }

        $end = $this->end !== null && $this->end > $grantEndsAt ? $this->end : $grantEndsAt;

        return new self($this->start, $end, false);
    }
}
