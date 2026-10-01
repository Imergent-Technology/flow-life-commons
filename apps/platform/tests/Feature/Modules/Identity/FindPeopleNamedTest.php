<?php

declare(strict_types=1);

use App\Modules\Identity\Application\FindPeopleNamed;
use App\Modules\Identity\Application\PersonSummary;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

/*
 * FindPeopleNamed: the exact, case-insensitive lookup of People by display name that duplicate advice composes (CRM,
 * first). An Identity Application seam: PersonSummary only, no Account data, no route. Runs on MariaDB and PostgreSQL:
 * `lower()` and the equality are exactly what the engines could differ on.
 */

/** @return list<string> */
function namedIds(string $name): array
{
    return array_map(static fn (PersonSummary $p): string => $p->id->value, app(FindPeopleNamed::class)($name));
}

it('finds the People with exactly this name, ignoring case and surrounding whitespace', function () {
    $ada = Identity::savedPerson('Ada Lovelace');
    $twin = Identity::savedPerson('ADA LOVELACE');
    Identity::savedPerson('Ada Lovelace Jr');
    Identity::savedPerson('Grace Hopper');

    $found = namedIds('  ada lovelace ');

    expect($found)->toEqualCanonicalizing([$ada->id->value, $twin->id->value]);
});

it('matches exactly, not as a fragment: LIKE wildcards are ordinary characters and a longer name is not a match', function () {
    $plain = Identity::savedPerson('A_B');
    Identity::savedPerson('AxB');
    Identity::savedPerson('A%');
    Identity::savedPerson('Anna');

    expect(namedIds('a_b'))->toBe([$plain->id->value])
        ->and(namedIds('A'))->toBe([])
        ->and(namedIds('%'))->toBe([])
        ->and(namedIds('Ann'))->toBe([]);
});

it('returns nothing for a blank name, and PersonSummary and nothing else', function () {
    $ada = Identity::savedPerson('Ada');
    Identity::savedActiveAccount('ada.login@example.org', name: 'Ada Account');

    expect(namedIds(''))->toBe([])
        ->and(namedIds("  \t "))->toBe([]);

    $found = app(FindPeopleNamed::class)('ada');
    expect($found)->toHaveCount(1)
        ->and($found[0])->toBeInstanceOf(PersonSummary::class)
        ->and(array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass($found[0]))->getProperties()))->toBe(['id', 'displayName'])
        ->and($found[0]->id->equals($ada->id))->toBeTrue();
});

it('orders by id and is bounded, so the answer is deterministic and the work is not open-ended', function () {
    $rows = [];
    for ($i = 0; $i < FindPeopleNamed::MAX_RESULTS + 20; $i++) {
        $rows[] = ['id' => PersonId::generate()->value, 'display_name' => 'Same Name', 'created_at' => '2026-10-01 12:00:00', 'updated_at' => '2026-10-01 12:00:00'];
    }
    DB::table('people')->insert($rows);

    $ids = namedIds('same name');
    $sorted = $ids;
    sort($sorted);

    expect($ids)->toHaveCount(FindPeopleNamed::MAX_RESULTS)->and($ids)->toBe($sorted)
        ->and(namedIds('same name'))->toBe($ids);
});
