<?php

declare(strict_types=1);

use App\Modules\Identity\Application\FindPeople;
use App\Shared\Domain\PersonId;
use Tests\Support\Identity;

/*
 * FindPeople: the batched read another module composes a display name from (Work Package 5),
 * so a Membership admin list can show one without querying Identity's tables directly or
 * looking a Person up once per row.
 */

it('returns a summary for each Person found, keyed by id', function () {
    $ada = Identity::savedPerson('Ada Lovelace');
    $grace = Identity::savedPerson('Grace Hopper');

    $summaries = app(FindPeople::class)([$ada->id, $grace->id]);

    expect($summaries)->toHaveCount(2)
        ->and($summaries[$ada->id->value]->displayName)->toBe('Ada Lovelace')
        ->and($summaries[$grace->id->value]->displayName)->toBe('Grace Hopper');
});

it('omits an id with no matching Person, rather than throwing', function () {
    $ada = Identity::savedPerson('Ada Lovelace');

    $summaries = app(FindPeople::class)([$ada->id, PersonId::generate()]);

    expect($summaries)->toHaveCount(1)->and($summaries)->toHaveKey($ada->id->value);
});

it('returns nothing for an empty list, without a query', function () {
    expect(app(FindPeople::class)([]))->toBe([]);
});
