<?php

declare(strict_types=1);

namespace App\Modules\Membership\Domain;

use DateTimeImmutable;

/**
 * Current membership access, DERIVED from a Person's grants at one instant (ADR 0028). Never
 * stored: there is no status column and no expiry job, so this is recomputed on every read.
 *
 * Intervals are half-open `[startsAt, endsAt)`: a grant covers its instant AT `startsAt` and
 * no longer covers it AT `endsAt`. Non-revoked grants that overlap or exactly touch merge into
 * one continuous coverage run; the run containing the requested instant decides the answer.
 *
 * An open-ended grant makes the ENTIRE run it joins open-ended, including every grant that
 * starts after it: nothing can start later than "no end", so once a run is open-ended it
 * absorbs every later grant into the same run, whatever that later grant's own term is. A
 * bounded grant that merely touches a later open-ended one is therefore reported as
 * open-ended too, not as ending where its own bounded term would have.
 */
final readonly class MembershipState
{
    private function __construct(
        public bool $active,
        public ?DateTimeImmutable $currentAccessEndsAt,
        public bool $openEnded,
    ) {}

    /**
     * @param  list<MembershipGrant>  $grants  a Person's grants, any order, revoked or not
     */
    public static function at(array $grants, DateTimeImmutable $instant): self
    {
        foreach (self::coverageRuns($grants) as $run) {
            if ($run->start <= $instant && ($run->openEnded || ($run->end !== null && $instant < $run->end))) {
                return new self(true, $run->openEnded ? null : $run->end, $run->openEnded);
            }
        }

        return new self(false, null, false);
    }

    /**
     * Continuous coverage runs of non-revoked grants, ordered by start and never touching or
     * overlapping each other (anything that would have is already merged into one).
     *
     * @param  list<MembershipGrant>  $grants
     * @return list<MembershipCoverageRun>
     */
    private static function coverageRuns(array $grants): array
    {
        $covering = array_values(array_filter($grants, static fn (MembershipGrant $g): bool => ! $g->isRevoked()));
        usort($covering, static fn (MembershipGrant $a, MembershipGrant $b): int => $a->startsAt <=> $b->startsAt);

        $runs = [];
        foreach ($covering as $grant) {
            $lastIndex = $runs === [] ? null : array_key_last($runs);
            $last = $lastIndex === null ? null : $runs[$lastIndex];

            if ($last !== null && $last->touchedOrOverlappedBy($grant->startsAt)) {
                $runs[$lastIndex] = $last->extendedBy($grant->endsAt);
            } else {
                $runs[] = MembershipCoverageRun::startingWith($grant);
            }
        }

        return $runs;
    }
}
