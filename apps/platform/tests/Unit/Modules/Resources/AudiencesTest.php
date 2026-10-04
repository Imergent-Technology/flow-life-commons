<?php

declare(strict_types=1);

use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\AudienceSet;
use App\Modules\Resources\Domain\CardAudience;
use App\Modules\Resources\Domain\InvalidResourceInput;
use Tests\Support\Resources;

/*
 * Audiences (ADR 0037, decisions 38-41): a code-owned catalog, positive OR sets, and a Card that is never broader than its Pack.
 */

it('has exactly two audiences in Phase 1, guardian and member, and no volunteer, partner, vendor or artist', function () {
    expect(array_map(fn (Audience $a): string => $a->value, Audience::cases()))->toBe(['guardian', 'member'])
        ->and(Audience::tryFrom('volunteer'))->toBeNull()
        ->and(Audience::tryFrom('partner'))->toBeNull()
        ->and(Audience::tryFrom('Guardian'))->toBeNull(); // case-exact: nothing near-matches
});

it('treats a set as a set: duplicates collapse and the order is the catalog\'s, however it was built', function () {
    $a = AudienceSet::of(Audience::Member, Audience::Guardian, Audience::Member);
    $b = AudienceSet::of(Audience::Guardian, Audience::Member);

    expect($a->equals($b))->toBeTrue()->and($a->values())->toBe(['guardian', 'member'])
        ->and(AudienceSet::none()->isEmpty())->toBeTrue()
        ->and(AudienceSet::none()->values())->toBe([]);
});

it('qualifies a viewer who satisfies ANY audience in the set (positive OR), and nobody for the empty set', function () {
    $pack = AudienceSet::of(Audience::Guardian, Audience::Member);

    expect($pack->intersects(AudienceSet::of(Audience::Guardian)))->toBeTrue()
        ->and($pack->intersects(AudienceSet::of(Audience::Member)))->toBeTrue()
        ->and($pack->intersects(AudienceSet::of(Audience::Guardian, Audience::Member)))->toBeTrue()
        ->and(AudienceSet::of(Audience::Guardian)->intersects(AudienceSet::of(Audience::Member)))->toBeFalse()
        ->and(AudienceSet::none()->intersects($pack))->toBeFalse()
        ->and($pack->intersects(AudienceSet::none()))->toBeFalse();
});

it('inherits the Pack\'s audiences, so the Card follows later changes to the Pack', function () {
    $card = CardAudience::inherit();

    expect($card->mode)->toBe(AudienceMode::Inherit)
        ->and($card->effective(AudienceSet::of(Audience::Guardian))->values())->toBe(['guardian'])
        ->and($card->effective(AudienceSet::of(Audience::Guardian, Audience::Member))->values())->toBe(['guardian', 'member'])
        ->and($card->effective(AudienceSet::none())->isEmpty())->toBeTrue();
});

it('narrows to a non-empty SUBSET of the Pack\'s audiences, fixed until changed', function () {
    $pack = AudienceSet::of(Audience::Guardian, Audience::Member);
    $narrowed = CardAudience::narrowedWithin(AudienceSet::of(Audience::Guardian), $pack);

    expect($narrowed->mode)->toBe(AudienceMode::Narrowed)
        ->and($narrowed->effective($pack)->values())->toBe(['guardian'])
        // A narrowed Card does not GROW when its Pack's set does...
        ->and($narrowed->effective(AudienceSet::of(Audience::Guardian, Audience::Member))->values())->toBe(['guardian']);
});

it('refuses to narrow to something broader than the Pack, or to nothing: a Card is never broader than its Pack', function (array $card, array $pack) {
    $make = fn () => CardAudience::narrowedWithin(AudienceSet::fromList(Resources::audiences($card)), AudienceSet::fromList(Resources::audiences($pack)));

    expect($make)->toThrow(InvalidResourceInput::class);

    try {
        $make();
    } catch (InvalidResourceInput $e) {
        expect($e->problem)->toBe('card_audience_not_subset')->and($e->field)->toBe('audiences');
    }
})->with([
    'member on a guardian-only pack' => [[Audience::Member], [Audience::Guardian]],
    'both on a guardian-only pack' => [[Audience::Guardian, Audience::Member], [Audience::Guardian]],
    'anything on a pack with none' => [[Audience::Guardian], []],
    'nothing at all' => [[], [Audience::Guardian, Audience::Member]],
]);

it('intersects at projection even if a stored narrowing were wider than its Pack, so it can never widen what a viewer sees', function () {
    // The invariant is held on every write; this is the second layer. Simulate a broken row: a narrowed set wider than the Pack.
    $broken = CardAudience::reconstitute(AudienceMode::Narrowed, AudienceSet::of(Audience::Guardian, Audience::Member));

    expect($broken->effective(AudienceSet::of(Audience::Guardian))->values())->toBe(['guardian'])
        ->and($broken->effective(AudienceSet::none())->isEmpty())->toBeTrue();
});

it('ignores any stored set on an inheriting Card, so stale rows can never narrow or widen it', function () {
    $card = CardAudience::reconstitute(AudienceMode::Inherit, AudienceSet::of(Audience::Member));

    expect($card->set->isEmpty())->toBeTrue()->and($card->effective(AudienceSet::of(Audience::Guardian))->values())->toBe(['guardian']);
});
