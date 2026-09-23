<?php

declare(strict_types=1);

use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Modules\Membership\Domain\MembershipState;
use App\Shared\Domain\PersonId;

/*
 * The temporal derivation matrix (ADR 0028), pinned exactly. Pure domain logic: no database,
 * no container. Intervals are half-open [startsAt, endsAt); non-revoked grants that overlap or
 * exactly touch merge into one continuous run, and an open-ended grant makes the whole run it
 * joins open-ended, including everything that starts after it.
 */

function at(string $instant): DateTimeImmutable
{
    return new DateTimeImmutable($instant, new DateTimeZone('UTC'));
}

function grantAt(string $startsAt, ?string $endsAt, ?string $revokedAt = null): MembershipGrant
{
    $starts = at($startsAt);
    $ends = $endsAt === null ? null : at($endsAt);
    $created = MembershipGrant::grant(MembershipGrantId::generate(), PersonId::generate(), $starts, $ends, MembershipGrantSource::Operator, null, null, $starts);

    if ($revokedAt === null) {
        return $created;
    }

    return MembershipGrant::reconstitute(
        $created->id, $created->personId, $created->startsAt, $created->endsAt, $created->source,
        $created->sourceReference, $created->grantedByAccountId, at($revokedAt), null, $created->createdAt,
    );
}

// 1. no grants
it('is inactive with no grants at all', function () {
    $state = MembershipState::at([], at('2026-06-15 00:00:00'));

    expect($state->active)->toBeFalse()->and($state->currentAccessEndsAt)->toBeNull()->and($state->openEnded)->toBeFalse();
});

// 2. one currently active bounded grant
it('is active during one currently active bounded grant, reporting its end', function () {
    $g = grantAt('2026-01-01 00:00:00', '2026-12-01 00:00:00');
    $state = MembershipState::at([$g], at('2026-06-15 00:00:00'));

    expect($state->active)->toBeTrue()->and($state->currentAccessEndsAt)->toEqual(at('2026-12-01 00:00:00'))->and($state->openEnded)->toBeFalse();
});

// 3. future grant only
it('is inactive before a future bounded grant starts', function () {
    $g = grantAt('2026-08-01 00:00:00', '2026-12-01 00:00:00');
    $state = MembershipState::at([$g], at('2026-01-01 00:00:00'));

    expect($state->active)->toBeFalse();
});

// 4. expired grant only
it('is inactive after a bounded grant has expired', function () {
    $g = grantAt('2026-01-01 00:00:00', '2026-03-01 00:00:00');
    $state = MembershipState::at([$g], at('2026-06-01 00:00:00'));

    expect($state->active)->toBeFalse();
});

// 5. T exactly at starts_at -> active
it('is active exactly at startsAt: the interval is inclusive of its start', function () {
    $g = grantAt('2026-01-01 00:00:00', '2026-03-01 00:00:00');
    $state = MembershipState::at([$g], at('2026-01-01 00:00:00'));

    expect($state->active)->toBeTrue();
});

// 6. T exactly at ends_at -> inactive for that grant
it('is inactive exactly at endsAt: the interval is exclusive of its end', function () {
    $g = grantAt('2026-01-01 00:00:00', '2026-03-01 00:00:00');
    $state = MembershipState::at([$g], at('2026-03-01 00:00:00'));

    expect($state->active)->toBeFalse();
});

// 7. gap between grants
it('is inactive in the gap between two bounded grants', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00');
    $b = grantAt('2026-04-01 00:00:00', '2026-05-01 00:00:00');
    $state = MembershipState::at([$a, $b], at('2026-03-01 00:00:00'));

    expect($state->active)->toBeFalse();
});

// 8. overlapping grants
it('merges overlapping grants into one continuous run, reporting the later end', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-03-01 00:00:00');
    $b = grantAt('2026-02-01 00:00:00', '2026-04-01 00:00:00');
    $state = MembershipState::at([$a, $b], at('2026-01-15 00:00:00'));

    expect($state->active)->toBeTrue()->and($state->currentAccessEndsAt)->toEqual(at('2026-04-01 00:00:00'));
});

// 9. exactly-touching grants
it('merges exactly-touching grants into one continuous run', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00');
    $b = grantAt('2026-02-01 00:00:00', '2026-03-01 00:00:00');
    $state = MembershipState::at([$a, $b], at('2026-01-15 00:00:00'));

    expect($state->active)->toBeTrue()->and($state->currentAccessEndsAt)->toEqual(at('2026-03-01 00:00:00'));
});

// 10. chain of three touching/overlapping grants
it('chains three touching/overlapping grants into one run, in any input order', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00');
    $b = grantAt('2026-02-01 00:00:00', '2026-03-15 00:00:00'); // overlaps c
    $c = grantAt('2026-03-01 00:00:00', '2026-04-01 00:00:00');
    $state = MembershipState::at([$c, $a, $b], at('2026-01-15 00:00:00'));

    expect($state->active)->toBeTrue()->and($state->currentAccessEndsAt)->toEqual(at('2026-04-01 00:00:00'));
});

// 11. T in the second continuous run after a gap
it('finds the second continuous run after a gap', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00');
    $b = grantAt('2026-04-01 00:00:00', '2026-06-01 00:00:00');
    $state = MembershipState::at([$a, $b], at('2026-05-01 00:00:00'));

    expect($state->active)->toBeTrue()->and($state->currentAccessEndsAt)->toEqual(at('2026-06-01 00:00:00'));
});

// 12. revoked grant ignored
it('ignores a revoked grant whose term does not otherwise cover the instant', function () {
    $g = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00', revokedAt: '2026-01-05 00:00:00');
    $state = MembershipState::at([$g], at('2026-06-01 00:00:00'));

    expect($state->active)->toBeFalse();
});

// 13. revoked grant that otherwise covers T
it('ignores a revoked grant even though its interval would otherwise cover the instant', function () {
    $g = grantAt('2026-01-01 00:00:00', '2026-12-01 00:00:00', revokedAt: '2026-01-10 00:00:00');
    $state = MembershipState::at([$g], at('2026-06-01 00:00:00'));

    expect($state->active)->toBeFalse();
});

// 14. all grants revoked
it('is inactive when every grant is revoked', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-06-01 00:00:00', revokedAt: '2026-01-02 00:00:00');
    $b = grantAt('2026-07-01 00:00:00', '2026-12-01 00:00:00', revokedAt: '2026-07-02 00:00:00');
    $state = MembershipState::at([$a, $b], at('2026-08-01 00:00:00'));

    expect($state->active)->toBeFalse();
});

// 15. open-ended active grant
it('is active and open-ended for a currently active open-ended grant', function () {
    $g = grantAt('2026-01-01 00:00:00', null);
    $state = MembershipState::at([$g], at('2026-06-01 00:00:00'));

    expect($state->active)->toBeTrue()->and($state->openEnded)->toBeTrue()->and($state->currentAccessEndsAt)->toBeNull();
});

// 16. future open-ended grant
it('is inactive before a future open-ended grant starts', function () {
    $g = grantAt('2026-08-01 00:00:00', null);
    $state = MembershipState::at([$g], at('2026-01-01 00:00:00'));

    expect($state->active)->toBeFalse();
});

// 17. revoked open-ended grant
it('ignores a revoked open-ended grant', function () {
    $g = grantAt('2026-01-01 00:00:00', null, revokedAt: '2026-01-10 00:00:00');
    $state = MembershipState::at([$g], at('2026-06-01 00:00:00'));

    expect($state->active)->toBeFalse();
});

// 18. bounded current grant touching a future open-ended grant -> current run is open-ended
it('makes a bounded current run open-ended when it touches a future open-ended grant', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00');
    $b = grantAt('2026-02-01 00:00:00', null);
    $state = MembershipState::at([$a, $b], at('2026-01-15 00:00:00'));

    expect($state->active)->toBeTrue()
        ->and($state->openEnded)->toBeTrue()
        ->and($state->currentAccessEndsAt)->toBeNull();
});

// 19. overlapping bounded grants where a later grant extends the run
it('extends the run end when a later overlapping grant runs longer', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-03-01 00:00:00');
    $b = grantAt('2026-02-01 00:00:00', '2026-06-01 00:00:00');
    $state = MembershipState::at([$a, $b], at('2026-01-15 00:00:00'));

    expect($state->currentAccessEndsAt)->toEqual(at('2026-06-01 00:00:00'));
});

// 20. overlapping grant entirely contained inside another does not shorten the run
it('does not shorten the run when an overlapping grant is entirely contained inside another', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-12-01 00:00:00');
    $b = grantAt('2026-03-01 00:00:00', '2026-04-01 00:00:00'); // wholly inside a
    $state = MembershipState::at([$a, $b], at('2026-06-01 00:00:00'));

    expect($state->currentAccessEndsAt)->toEqual(at('2026-12-01 00:00:00'));
});

// A contained grant sorted before its container must not shorten it either (order independence).
it('does not shorten the run when the contained grant is processed before its container', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-12-01 00:00:00');
    $b = grantAt('2026-03-01 00:00:00', '2026-04-01 00:00:00');
    $state = MembershipState::at([$b, $a], at('2026-06-01 00:00:00'));

    expect($state->currentAccessEndsAt)->toEqual(at('2026-12-01 00:00:00'));
});

// Negative-space control: two grants close but NOT touching (a one-second gap) must not merge.
it('does not merge two grants that are close but do not touch', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00');
    $b = grantAt('2026-02-01 00:00:01', '2026-03-01 00:00:00');
    $state = MembershipState::at([$a, $b], at('2026-02-01 00:00:00')); // exactly the one-second gap

    expect($state->active)->toBeFalse();
});

// --- Regression cases from the final branch audit. The algorithm already handled each; these pin it. -----------------

// A revoked grant sitting between two others is not a bridge: it neither joins them nor extends the run before it.
it('does not let a revoked grant bridge the grants on either side of it', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00');
    $bridge = grantAt('2026-02-01 00:00:00', '2026-03-01 00:00:00', revokedAt: '2026-01-05 00:00:00');
    $c = grantAt('2026-03-01 00:00:00', '2026-04-01 00:00:00');

    $first = MembershipState::at([$a, $bridge, $c], at('2026-01-15 00:00:00'));
    $inTheHole = MembershipState::at([$a, $bridge, $c], at('2026-02-15 00:00:00'));
    $third = MembershipState::at([$a, $bridge, $c], at('2026-03-15 00:00:00'));

    expect($first->active)->toBeTrue()->and($first->currentAccessEndsAt)->toEqual(at('2026-02-01 00:00:00')) // NOT carried on through c
        ->and($inTheHole->active)->toBeFalse()
        ->and($third->active)->toBeTrue()->and($third->currentAccessEndsAt)->toEqual(at('2026-04-01 00:00:00'));
});

it('does not let a revoked open-ended grant extend, or open-end, the run it overlaps', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00');
    $revokedOpen = grantAt('2026-01-15 00:00:00', null, revokedAt: '2026-01-16 00:00:00');
    $later = grantAt('2026-05-01 00:00:00', '2026-06-01 00:00:00');

    $state = MembershipState::at([$a, $revokedOpen, $later], at('2026-01-20 00:00:00'));

    expect($state->active)->toBeTrue()->and($state->openEnded)->toBeFalse()->and($state->currentAccessEndsAt)->toEqual(at('2026-02-01 00:00:00'));
});

// Only a run that is reached by touching or overlapping can be open-ended; an open-ended grant after a gap is a different run.
it('keeps an earlier run bounded when the open-ended grant is in a later, disjoint run', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00');
    $later = grantAt('2026-04-01 00:00:00', null);

    $early = MembershipState::at([$a, $later], at('2026-01-15 00:00:00'));
    $inGap = MembershipState::at([$a, $later], at('2026-03-01 00:00:00'));
    $inLater = MembershipState::at([$a, $later], at('2026-04-15 00:00:00'));

    expect($early->active)->toBeTrue()->and($early->openEnded)->toBeFalse()->and($early->currentAccessEndsAt)->toEqual(at('2026-02-01 00:00:00'))
        ->and($inGap->active)->toBeFalse()
        ->and($inLater->active)->toBeTrue()->and($inLater->openEnded)->toBeTrue()->and($inLater->currentAccessEndsAt)->toBeNull();
});

// The instant a term ends is the instant its successor starts: exactly there, access is continuous and belongs to the successor.
it('is active, through the successor\'s end, at the exact instant two touching grants meet', function () {
    $a = grantAt('2026-01-01 00:00:00', '2026-02-01 00:00:00');
    $b = grantAt('2026-02-01 00:00:00', '2026-03-01 00:00:00');

    $atBoundary = MembershipState::at([$a, $b], at('2026-02-01 00:00:00'));
    $justBefore = MembershipState::at([$a, $b], at('2026-01-31 23:59:59'));

    expect($atBoundary->active)->toBeTrue()->and($atBoundary->currentAccessEndsAt)->toEqual(at('2026-03-01 00:00:00'))
        ->and($justBefore->active)->toBeTrue()->and($justBefore->currentAccessEndsAt)->toEqual(at('2026-03-01 00:00:00')); // one continuous run either side
});

// The domain compares instants, not wall-clock strings: an offset on a grant or on T changes nothing about which instant it is.
it('compares instants, so grants and an instant expressed in different UTC offsets still touch and merge', function () {
    $a = grantAt('2026-01-01T02:00:00+02:00', '2026-02-01T02:00:00+02:00');   // = 00:00Z .. 2026-02-01T00:00Z
    $b = grantAt('2026-01-31T19:00:00-05:00', '2026-03-01T00:00:00+00:00');   // starts 2026-02-01T00:00Z exactly: touches a
    $instant = at('2026-02-01T05:30:00+05:30');                              // = 2026-02-01T00:00Z exactly

    $state = MembershipState::at([$b, $a], $instant);

    expect($state->active)->toBeTrue()
        ->and($state->currentAccessEndsAt?->getTimestamp())->toBe(at('2026-03-01 00:00:00')->getTimestamp())
        ->and(MembershipState::at([$a, $b], at('2026-01-15 12:00:00'))->currentAccessEndsAt?->getTimestamp())->toBe(at('2026-03-01 00:00:00')->getTimestamp());
});
