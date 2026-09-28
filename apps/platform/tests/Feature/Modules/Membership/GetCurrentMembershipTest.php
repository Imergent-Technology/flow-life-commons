<?php

declare(strict_types=1);

use App\Modules\Membership\Application\GetCurrentMembership;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use Illuminate\Support\Carbon;
use Tests\Support\Access;
use Tests\Support\Identity;
use Tests\Support\Membership;

/*
 * Membership\Application\GetCurrentMembership (ADR 0032, Work Package 2): a Person's OWN membership state,
 * with no capability check at all — reaching this is authenticated self-service, not an operator read.
 * Reuses the exact temporal derivation GetMembershipRecord uses (MembershipState::at over
 * MembershipGrantRepository::forPerson); what is specific here is proven directly, and the shared
 * derivation's exhaustive coverage stays in MembershipStateTest / GetMembershipRecordTest rather than
 * being repeated.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-28 12:00:00');
});

it('reports an active bounded grant, with its current access-through', function () {
    $person = Identity::savedPerson('Mia Member');
    Membership::savedGrant($person->id, Identity::now()->modify('-1 month'), Identity::now()->modify('+11 months'));

    $record = app(GetCurrentMembership::class)($person->id);

    expect($record->personId)->toEqual($person->id)
        ->and($record->active)->toBeTrue()
        ->and($record->currentAccessEndsAt)->toEqual(Identity::now()->modify('+11 months'))
        ->and($record->openEnded)->toBeFalse()
        ->and($record->grants)->toHaveCount(1);
});

it('reports an active OPEN-ENDED grant', function () {
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now()->modify('-1 month'), null);

    $record = app(GetCurrentMembership::class)($person->id);

    expect($record->active)->toBeTrue()
        ->and($record->openEnded)->toBeTrue()
        ->and($record->currentAccessEndsAt)->toBeNull();
});

it('reports a FUTURE grant as not currently active, but present in history', function () {
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now()->modify('+1 month'), Identity::now()->modify('+13 months'));

    $record = app(GetCurrentMembership::class)($person->id);

    expect($record->active)->toBeFalse()
        ->and($record->currentAccessEndsAt)->toBeNull()
        ->and($record->grants)->toHaveCount(1);
});

it('reports an EXPIRED grant as not currently active, but present in history', function () {
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));

    $record = app(GetCurrentMembership::class)($person->id);

    expect($record->active)->toBeFalse()
        ->and($record->currentAccessEndsAt)->toBeNull()
        ->and($record->grants)->toHaveCount(1);
});

it('reports a REVOKED grant as not currently active, but present in history', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();
    $grant = Membership::savedGrant($person->id, Identity::now()->modify('-1 month'), Identity::now()->modify('+11 months'));
    app(MembershipGrantRepository::class)->revoke($grant->id, $viewer->id, Identity::now());

    $record = app(GetCurrentMembership::class)($person->id);

    expect($record->active)->toBeFalse()
        ->and($record->grants)->toHaveCount(1)
        ->and($record->grants[0]->isRevoked())->toBeTrue();
});

it('reports a Person with MULTIPLE historical grants: current state from the live ones, full history regardless', function () {
    $viewer = Access::admin('viewer@example.org');
    $person = Identity::savedPerson();
    $ended = Membership::savedGrant($person->id, Identity::now()->modify('-2 years'), Identity::now()->modify('-1 year'));
    $revoked = Membership::savedGrant($person->id, Identity::now()->modify('-6 months'), Identity::now()->modify('+6 months'));
    app(MembershipGrantRepository::class)->revoke($revoked->id, $viewer->id, Identity::now()->modify('-1 month'));
    Membership::savedGrant($person->id, Identity::now()->modify('-1 week'), Identity::now()->modify('+1 year'));

    $record = app(GetCurrentMembership::class)($person->id);

    expect($record->active)->toBeTrue()
        ->and($record->currentAccessEndsAt)->toEqual(Identity::now()->modify('+1 year'))
        ->and($record->grants)->toHaveCount(3);
});

it('reports a Person with NO grants at all as inactive, with empty history — never a member is a normal answer', function () {
    $person = Identity::savedPerson();

    $record = app(GetCurrentMembership::class)($person->id);

    expect($record->active)->toBeFalse()
        ->and($record->currentAccessEndsAt)->toBeNull()
        ->and($record->openEnded)->toBeFalse()
        ->and($record->grants)->toBe([]);
});

it('accepts an explicit instant, distinct from now', function () {
    $person = Identity::savedPerson();
    Membership::savedGrant($person->id, Identity::now(), Identity::now()->modify('+1 month'));

    $past = app(GetCurrentMembership::class)($person->id, Identity::now()->modify('-1 day'));
    $future = app(GetCurrentMembership::class)($person->id, Identity::now()->modify('+2 months'));

    expect($past->active)->toBeFalse()->and($future->active)->toBeFalse();
});
