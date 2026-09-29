<?php

declare(strict_types=1);

use App\Modules\Identity\Application\PeopleDirectory;
use App\Modules\Identity\Application\PeoplePage;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\PersonSummary;
use App\Modules\Identity\Application\SearchPeople;
use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\PersonId;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Identity;

/*
 * SearchPeople: the paged read of the Person registry a module that enriches People (CRM, first) composes its
 * directory from. Identity Application seam only: no route, no capability, no Account data. Runs on both engines
 * (`./flow test backend --pgsql`): the name match, the escape and the ordering are exactly what the engines differ on.
 */

/**
 * @param  class-string  $class
 * @return list<string>
 */
function propertyNames(string $class): array
{
    return array_map(
        fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass($class))->getProperties(),
    );
}

/** @return list<PersonId> */
function idsOf(PeoplePage $page): array
{
    return array_map(static fn (PersonSummary $p): PersonId => $p->id, $page->people);
}

/** @return list<string> */
function namesOf(PeoplePage $page): array
{
    return array_map(static fn (PersonSummary $p): string => $p->displayName, $page->people);
}

/**
 * @param  list<PersonId>|null  $include
 * @param  list<PersonId>|null  $restrict
 */
function search(?string $text = null, ?array $include = null, ?array $restrict = null, int $page = 1, int $perPage = 25): PeoplePage
{
    return app(SearchPeople::class)(new PeopleQuery($text, $include, $restrict, $page, $perPage));
}

/**
 * Direct bulk insert of People with the given names, returning their ids in the order given.
 *
 * @param  list<string>  $names
 * @return list<PersonId>
 */
function bulkPeople(array $names): array
{
    $ids = [];
    $rows = [];
    foreach ($names as $name) {
        $id = PersonId::generate();
        $ids[] = $id;
        $rows[] = ['id' => $id->value, 'display_name' => $name, 'created_at' => '2026-09-19 12:00:00', 'updated_at' => '2026-09-19 12:00:00'];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('people')->insert($chunk);
    }

    return $ids;
}

/** @return list<string> the SELECT statements run while $work does */
function queriesDuring(Closure $work): array
{
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$queries): void {
        $queries[] = $q->sql;
    });
    $work();

    return $queries;
}

// --- The projection ---------------------------------------------------------------------------------------------

it('returns every Person, paged, as an id and a display name', function () {
    $ada = Identity::savedPerson('Ada Lovelace');
    $grace = Identity::savedPerson('Grace Hopper');

    $page = search();

    expect($page->total)->toBe(2)
        ->and($page->page)->toBe(1)
        ->and($page->people)->toHaveCount(2)
        ->and($page->people[0])->toBeInstanceOf(PersonSummary::class)
        ->and($page->people[0]->id->equals($ada->id))->toBeTrue()
        ->and($page->people[0]->displayName)->toBe('Ada Lovelace')
        ->and($page->people[1]->id->equals($grace->id))->toBeTrue();
});

it('lists a Person with no Account, no Membership and no role: the directory is the registry', function () {
    $bare = Identity::savedPerson('Bare Person');

    expect(idsOf(search()))->toEqual([$bare->id]);
});

it('exposes nothing beyond the id and the display name', function () {
    expect(propertyNames(PersonSummary::class))->toEqualCanonicalizing(['id', 'displayName'])
        ->and(propertyNames(PeoplePage::class))->toEqualCanonicalizing(['people', 'page', 'perPage', 'total']);
});

// --- Text --------------------------------------------------------------------------------------------------------

it('matches the display name as a case-insensitive fragment', function () {
    Identity::savedPerson('Ada Lovelace');
    Identity::savedPerson('Grace Hopper');
    Identity::savedPerson('Adam Smith');

    expect(namesOf(search('ada')))->toBe(['Ada Lovelace', 'Adam Smith'])
        ->and(namesOf(search('LOVE')))->toBe(['Ada Lovelace'])
        ->and(namesOf(search('  hopper  ')))->toBe(['Grace Hopper'])
        ->and(search('nobody-by-this-name')->people)->toBe([]);
});

it('treats null, empty and whitespace-only text as no text at all', function () {
    bulkPeople(['One', 'Two', 'Three']);

    expect(search(null)->total)->toBe(3)
        ->and(search('')->total)->toBe(3)
        ->and(search("  \t ")->total)->toBe(3);
});

it('treats LIKE wildcards and the escape character as ordinary characters', function () {
    bulkPeople(['100% Real', '100 Real', 'a_b', 'axb', 'fifty!off', 'fiftyoff']);

    expect(namesOf(search('%')))->toBe(['100% Real'])
        ->and(namesOf(search('100%')))->toBe(['100% Real'])
        ->and(namesOf(search('_')))->toBe(['a_b'])
        ->and(namesOf(search('a_b')))->toBe(['a_b'])
        ->and(namesOf(search('!')))->toBe(['fifty!off'])
        ->and(namesOf(search('y!o')))->toBe(['fifty!off']);
});

// --- Order and paging --------------------------------------------------------------------------------------------

it('orders by lower-cased name, then by id, so equal names never swap between pages', function () {
    // Same name in three casings, inserted in DESCENDING id order so that an unstable tie shows up.
    $ids = [];
    for ($i = 0; $i < 6; $i++) {
        $ids[] = PersonId::generate();
    }
    usort($ids, fn (PersonId $a, PersonId $b): int => strcmp($a->value, $b->value));
    $names = ['Sam', 'SAM', 'sam', 'Sam', 'sAm', 'SAM'];
    foreach (array_reverse(array_keys($ids)) as $i) {
        DB::table('people')->insert(['id' => $ids[$i]->value, 'display_name' => $names[$i], 'created_at' => '2026-09-19 12:00:00', 'updated_at' => '2026-09-19 12:00:00']);
    }
    $before = Identity::savedPerson('Aaron');
    $after = Identity::savedPerson('Zed');

    $seen = [];
    for ($page = 1; $page <= 4; $page++) {
        foreach (idsOf(search(null, null, null, $page, 2)) as $id) {
            $seen[] = $id->value;
        }
    }

    expect($seen)->toBe([$before->id->value, ...array_map(fn (PersonId $i): string => $i->value, $ids), $after->id->value]);
});

it('states the ordering in the query itself, not left to the engine', function () {
    bulkPeople(['A', 'B']);

    $statements = queriesDuring(fn () => search());

    $select = collect($statements)->first(fn (string $sql): bool => str_contains($sql, 'limit'));
    expect($select)->toMatch('/order by lower\(display_name\) asc, [`"]id[`"] asc/');
});

it('pages with a stable total, and an out-of-range page is empty rather than an error', function () {
    bulkPeople(['P1', 'P2', 'P3', 'P4', 'P5']);

    $first = search(null, null, null, 1, 2);
    $last = search(null, null, null, 3, 2);
    $beyond = search(null, null, null, 4, 2);

    expect(namesOf($first))->toBe(['P1', 'P2'])
        ->and(namesOf(search(null, null, null, 2, 2)))->toBe(['P3', 'P4'])
        ->and(namesOf($last))->toBe(['P5'])
        ->and($beyond->people)->toBe([])
        ->and([$first->total, $last->total, $beyond->total])->toBe([5, 5, 5])
        ->and($first->lastPage())->toBe(3);
});

it('bounds the page number and the page size', function () {
    bulkPeople(array_map(fn (int $i): string => sprintf('Person %03d', $i), range(1, 101)));

    $huge = search(null, null, null, 1, 5000);
    $zero = search(null, null, null, 0, 0);
    $negative = search(null, null, null, -3, -1);

    expect($huge->perPage)->toBe(100)
        ->and($huge->people)->toHaveCount(100)
        ->and($huge->total)->toBe(101)
        ->and($zero->page)->toBe(1)
        ->and($zero->perPage)->toBe(1)
        ->and($zero->people)->toHaveCount(1)
        ->and($negative->page)->toBe(1);
});

// --- includeIds --------------------------------------------------------------------------------------------------

it('adds included Persons to a text match, whatever their names', function () {
    $ada = Identity::savedPerson('Ada Lovelace');
    $grace = Identity::savedPerson('Grace Hopper');
    Identity::savedPerson('Alan Turing');

    // "Grace" was matched by something the caller owns; "ada" matches Ada by name.
    expect(idsOf(search('ada', [$grace->id])))->toEqual([$ada->id, $grace->id])
        ->and(namesOf(search('no-name-matches', [$grace->id])))->toBe(['Grace Hopper']);
});

it('ignores included Persons when there is no text: every Person is already selected', function () {
    $ada = Identity::savedPerson('Ada Lovelace');
    Identity::savedPerson('Grace Hopper');

    expect(search('', [$ada->id])->total)->toBe(2)
        ->and(search(null, [$ada->id])->total)->toBe(2);
});

it('treats null and empty includeIds alike, and an unknown included id as nothing', function () {
    $ada = Identity::savedPerson('Ada Lovelace');
    Identity::savedPerson('Grace Hopper');

    expect(namesOf(search('ada', null)))->toBe(['Ada Lovelace'])
        ->and(namesOf(search('ada', [])))->toBe(['Ada Lovelace'])
        ->and(namesOf(search('ada', [PersonId::generate()])))->toBe(['Ada Lovelace'])
        ->and(search('no-name-matches', [PersonId::generate()])->people)->toBe([])
        ->and($ada->displayName)->toBe('Ada Lovelace');
});

// --- restrictToIds -----------------------------------------------------------------------------------------------

it('narrows to the restricted Persons: an intersection, never a union', function () {
    $ada = Identity::savedPerson('Ada Lovelace');
    $grace = Identity::savedPerson('Grace Hopper');
    Identity::savedPerson('Alan Turing');

    expect(idsOf(search(null, null, [$ada->id, $grace->id])))->toEqual([$ada->id, $grace->id])
        ->and(idsOf(search('a', null, [$grace->id])))->toEqual([$grace->id])
        ->and(search('turing', null, [$ada->id])->people)->toBe([]);
});

it('selects nobody for an explicitly empty restriction, without touching the database', function () {
    Identity::savedPerson('Ada Lovelace');

    $page = null;
    $statements = queriesDuring(function () use (&$page) {
        $page = search(null, null, []);
    });
    assert($page instanceof PeoplePage);

    expect($page->people)->toBe([])
        ->and($page->total)->toBe(0)
        ->and($statements)->toBe([]);
});

it('does not read null as an empty restriction', function () {
    bulkPeople(['One', 'Two']);

    expect(search(null, null, null)->total)->toBe(2);
});

it('gives nothing for restricted ids that name no Person', function () {
    Identity::savedPerson('Ada Lovelace');

    expect(search(null, null, [PersonId::generate(), PersonId::generate()])->total)->toBe(0);
});

it('composes include and restrict: the union is taken first, then intersected', function () {
    $ada = Identity::savedPerson('Ada Lovelace');
    $adam = Identity::savedPerson('Adam Smith');
    $grace = Identity::savedPerson('Grace Hopper');
    $alan = Identity::savedPerson('Alan Turing');

    // text "ad" matches Ada and Adam; Grace is included; only Adam, Grace and Alan are allowed through.
    $result = search('ad', [$grace->id], [$adam->id, $grace->id, $alan->id]);

    expect(idsOf($result))->toEqual([$adam->id, $grace->id])
        ->and($result->total)->toBe(2)
        ->and($ada->displayName)->toBe('Ada Lovelace');
});

it('ignores ids repeated within and across the sets', function () {
    $ada = Identity::savedPerson('Ada Lovelace');
    Identity::savedPerson('Grace Hopper');

    $result = search('ada', [$ada->id, $ada->id], [$ada->id, $ada->id, $ada->id]);

    expect(idsOf($result))->toEqual([$ada->id])->and($result->total)->toBe(1);
});

it('refuses an id set too large for one statement', function () {
    $ids = array_map(fn (int $i): PersonId => PersonId::generate(), range(1, PeopleQuery::MAX_ID_SET + 1));

    expect(fn () => new PeopleQuery(null, $ids))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new PeopleQuery(null, null, $ids))->toThrow(InvalidArgumentException::class);
});

// --- Account data stays out ---------------------------------------------------------------------------------------

it('neither matches nor reveals a login email: that stays behind identity.accounts.view', function () {
    $person = Identity::savedPerson('Ada Lovelace');
    Identity::savedActiveAccount('secret.login@example.org', name: 'Someone Else');
    $account = Identity::savedInvitedAccount('ada.private@example.org');

    expect(search('secret.login')->people)->toBe([])
        ->and(search('example.org')->people)->toBe([])
        ->and(search('ada.private')->people)->toBe([])
        ->and($account->personId->value)->not->toBe($person->id->value);
});

it('lists a Person with an Account exactly like one without', function () {
    $account = Identity::savedActiveAccount('ada@example.org', name: 'Ada Lovelace');

    $page = search('ada');

    expect($page->people)->toHaveCount(1)
        ->and($page->people[0]->id->equals($account->personId))->toBeTrue();
});

// --- Id-set composition at Flow Life's scale, on both engines ------------------------------------------------------

it('composes a 1,000-id restriction: correct rows, order and paging, two statements per page', function () {
    // 1,200 People with mixed-case names whose lower-cased order is the number order.
    $names = array_map(fn (int $i): string => ($i % 2 === 0 ? 'Person ' : 'PERSON ').sprintf('%04d', $i), range(1, 1200));
    $ids = bulkPeople($names);

    // A caller's candidate set: 900 real People (every other one, then some) plus 100 ids that name nobody, shuffled.
    $real = array_slice($ids, 0, 900);
    $candidates = [...$real, ...array_map(fn (int $i): PersonId => PersonId::generate(), range(1, 100))];
    mt_srand(7);
    shuffle($candidates);
    expect($candidates)->toHaveCount(1000);

    $seen = [];
    $statements = [];
    for ($page = 1; $page <= 9; $page++) {
        $statements = [...$statements, ...queriesDuring(function () use (&$seen, $candidates, $page) {
            foreach (idsOf(search(null, null, $candidates, $page, 100)) as $id) {
                $seen[] = $id->value;
            }
        })];
    }

    expect($seen)->toBe(array_map(fn (PersonId $i): string => $i->value, $real)) // number order == creation order here
        ->and(search(null, null, $candidates, 1, 100)->total)->toBe(900)
        ->and(search(null, null, $candidates, 10, 100)->people)->toBe([])
        ->and($statements)->toHaveCount(18); // a count and a page, nine times: nothing per row
});

it('composes a 1,000-id include with a text match that names none of them', function () {
    $ids = bulkPeople(array_map(fn (int $i): string => sprintf('Member %04d', $i), range(1, 1200)));
    $included = array_slice($ids, 100, 1000);

    $page = null;
    $statements = queriesDuring(function () use (&$page, $included) {
        $page = search('no-name-contains-this', $included, null, 3, 100);
    });
    assert($page instanceof PeoplePage);

    expect($page->total)->toBe(1000)
        ->and($page->people)->toHaveCount(100)
        ->and($page->people[0]->id->equals($included[200]))->toBeTrue()
        ->and($statements)->toHaveCount(2);
});

it('composes a 1,000-id include AND restriction together', function () {
    $ids = bulkPeople(array_map(fn (int $i): string => sprintf('Friend %04d', $i), range(1, 1500)));
    $included = array_slice($ids, 0, 1000);   // 0..999
    $restricted = array_slice($ids, 500, 1000); // 500..1499

    $page = search('zzz', $included, $restricted, 1, 100);

    expect($page->total)->toBe(500) // 500..999
        ->and($page->people[0]->id->equals($ids[500]))->toBeTrue();
});

it('is served by the registry the Person repository writes', function () {
    // Positive control for the fixtures above: a Person saved through the aggregate is found by the read port.
    $person = Person::create(PersonId::generate(), 'Control Person', Identity::now());
    app(PersonRepository::class)->save($person);

    expect(app(PeopleDirectory::class))->toBeInstanceOf(PeopleDirectory::class)
        ->and(idsOf(search('control')))->toEqual([$person->id]);
});
