<?php

declare(strict_types=1);

use App\Modules\Membership\Domain\InvalidMembershipTerm;
use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Shared\Domain\PersonId;

function makeGrant(?DateTimeImmutable $endsAt, ?string $sourceReference = null): MembershipGrant
{
    $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

    return MembershipGrant::grant(
        MembershipGrantId::generate(), PersonId::generate(), $now, $endsAt,
        MembershipGrantSource::Operator, $sourceReference, null, $now,
    );
}

it('accepts a valid bounded term', function () {
    $grant = makeGrant(new DateTimeImmutable('2026-02-01 00:00:00', new DateTimeZone('UTC')));

    expect($grant->endsAt)->not->toBeNull()->and($grant->isRevoked())->toBeFalse();
});

it('accepts an open-ended term', function () {
    expect(makeGrant(null)->endsAt)->toBeNull();
});

it('refuses a zero-length term', function () {
    makeGrant(new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')));
})->throws(InvalidMembershipTerm::class);

it('refuses a backwards term', function () {
    makeGrant(new DateTimeImmutable('2025-12-01 00:00:00', new DateTimeZone('UTC')));
})->throws(InvalidMembershipTerm::class);

it('accepts no source reference', function () {
    expect(makeGrant(null)->sourceReference)->toBeNull();
});

it('accepts a source reference at exactly the maximum length', function () {
    $grant = makeGrant(null, str_repeat('a', MembershipGrant::MAX_SOURCE_REFERENCE_LENGTH));

    expect($grant->sourceReference)->toHaveLength(MembershipGrant::MAX_SOURCE_REFERENCE_LENGTH);
});

it('refuses a source reference longer than the maximum', function () {
    makeGrant(null, str_repeat('a', MembershipGrant::MAX_SOURCE_REFERENCE_LENGTH + 1));
})->throws(InvalidArgumentException::class);

it('generates a ULID identity the same way every other aggregate does', function () {
    $grant = makeGrant(null);

    expect($grant->id->value)->toHaveLength(26)->and($grant->id->value)->toBe(strtolower($grant->id->value));
});

it('reconstitutes a revoked row without re-validating it', function () {
    $now = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    $person = PersonId::generate();

    $grant = MembershipGrant::reconstitute(
        MembershipGrantId::generate(), $person, $now, $now->modify('+1 month'),
        MembershipGrantSource::Operator, null, null,
        $now->modify('+1 day'), null, $now,
    );

    expect($grant->isRevoked())->toBeTrue()->and($grant->revokedAt)->toEqual($now->modify('+1 day'));
});
