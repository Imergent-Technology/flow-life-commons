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
