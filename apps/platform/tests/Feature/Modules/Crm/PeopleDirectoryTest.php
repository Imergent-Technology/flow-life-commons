<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Crm\Application\PagePeopleDirectory;
use App\Modules\Crm\Application\PeopleDirectoryPage;
use App\Modules\Crm\Application\SearchTooBroad;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Identity\Application\PeopleQuery;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\Access;
use Tests\Support\Crm;
use Tests\Support\Identity;

/*
 * The People directory (ADR 0034): every Person Identity holds, narrowed by CRM's own text matches and tag. Composed
 * through Identity's SearchPeople, so ordering and paging are Identity's. Runs on MariaDB and PostgreSQL.
 */

function directory(Actor $by, ?string $text = null, ?ContactTagId $tag = null, int $page = 1, int $perPage = 25): PeopleDirectoryPage
{
    return app(PagePeopleDirectory::class)($by, $text, $tag, $page, $perPage);
}

/** @return list<string> */
function directoryNames(PeopleDirectoryPage $page): array
{
    return array_map(fn ($listing): string => $listing->person->displayName, $page->people);
}

it('lists EVERY Person, whether or not CRM knows anything about them', function () {
    $by = Crm::manager();
    Identity::savedPerson('Bare Person');                                   // nothing at all
    Identity::savedActiveAccount('operator@example.org', name: 'Has Account'); // an Account, no CRM data
    $known = Identity::savedPerson('Known');
    Crm::email($by, $known->id, 'known@example.org');

    $page = directory($by);

    expect(directoryNames($page))->toContain('Bare Person', 'Has Account', 'Known')
        ->and($page->total)->toBeGreaterThanOrEqual(3)
        ->and(DB::table('contact_profiles')->count())->toBe(1); // reading creates no CRM rows
});

it('shows each Person\'s primary email and phone, and tags', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Crm::email($by, $ada->id, 'secondary@example.org');           // first of its kind: primary
    Crm::email($by, $ada->id, 'ada@example.org', primary: true);   // now the primary
    Crm::phone($by, $ada->id, '555 010 0100');
    Crm::tagPerson($by, $ada->id, [Crm::tag($by, 'Partner'), Crm::tag($by, 'Artist')]);

    $row = directory($by, 'Ada')->people[0];

    expect($row->person->displayName)->toBe('Ada')
        ->and($row->primaryEmail)->toBe('ada@example.org')
        ->and($row->primaryPhone)->toBe('555 010 0100')
        ->and(array_map(fn ($t): string => $t->name, $row->tags))->toBe(['Artist', 'Partner']);
});

it('finds a Person by display name, case-insensitively', function () {
    $by = Crm::manager();
    Identity::savedPerson('Ada Lovelace');
    Identity::savedPerson('Grace Hopper');

    expect(directoryNames(directory($by, 'LOVE')))->toBe(['Ada Lovelace'])
        ->and(directoryNames(directory($by, 'nobody-matches')))->toBe([]);
});

it('finds a Person by an email recorded in CRM, even though their name does not match', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelace');
    Identity::savedPerson('Grace Hopper');
    Crm::email($by, $ada->id, 'Countess.Of.Lovelace@Example.org');

    expect(directoryNames(directory($by, 'countess.of')))->toBe(['Ada Lovelace'])
        ->and(directoryNames(directory($by, '@EXAMPLE.org')))->toBe(['Ada Lovelace']);
});

it('finds a Person by the digits of a phone number recorded in CRM, however it was written', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelace');
    Identity::savedPerson('Grace Hopper');
    Crm::phone($by, $ada->id, '(555) 010-0100');

    expect(directoryNames(directory($by, '555 0100')))->toBe(['Ada Lovelace'])
        ->and(directoryNames(directory($by, '010-01')))->toBe(['Ada Lovelace'])
        ->and(directoryNames(directory($by, '999')))->toBe([]);
});

it('does not take fewer than three digits as a phone search', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelace');
    Crm::phone($by, $ada->id, '(555) 010-0100');

    expect(directoryNames(directory($by, '55')))->toBe([]);
});

it('does NOT find a Person by their Account\'s login email: that stays behind identity.accounts.view', function () {
    $by = Crm::manager();
    $account = Identity::savedActiveAccount('login.only@example.org', name: 'Login Only');

    expect(directoryNames(directory($by, 'login.only')))->toBe([])
        ->and(directoryNames(directory($by, 'login.only@example.org')))->toBe([]);

    // Only once the address is recorded as a CRM contact method does CRM search find them, by CRM's own data.
    Crm::email($by, $account->personId, 'login.only@example.org');
    expect(directoryNames(directory($by, 'login.only')))->toBe(['Login Only']);
});

it('treats LIKE wildcards in a search as ordinary characters', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $grace = Identity::savedPerson('Grace');
    Crm::email($by, $ada->id, 'a_b@example.org');
    Crm::email($by, $grace->id, 'axb@example.org');

    expect(directoryNames(directory($by, 'a_b')))->toBe(['Ada'])
        ->and(directoryNames(directory($by, '%')))->toBe([]);
});

it('filters by tag, composes it with text, and gives nobody for an unused or unknown tag', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelace');
    $adam = Identity::savedPerson('Adam Smith');
    $grace = Identity::savedPerson('Grace Hopper');
    $partner = Crm::tag($by, 'Partner');
    $donor = Crm::tag($by, 'Donor');
    Crm::tagPerson($by, $ada->id, [$partner]);
    Crm::tagPerson($by, $grace->id, [$partner]);
    Crm::tagPerson($by, $adam->id, [$donor]);

    expect(directoryNames(directory($by, null, $partner)))->toBe(['Ada Lovelace', 'Grace Hopper'])
        ->and(directoryNames(directory($by, 'ad', $partner)))->toBe(['Ada Lovelace'])      // text AND tag
        ->and(directoryNames(directory($by, 'ad')))->toBe(['Ada Lovelace', 'Adam Smith'])
        ->and(directoryNames(directory($by, null, Crm::tag($by, 'Unused'))))->toBe([])      // an unused tag: nobody, not everybody
        ->and(directory($by, null, ContactTagId::generate())->total)->toBe(0);               // an unknown tag: nobody
});

it('composes a CRM text match with a tag: the Person must satisfy both', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $grace = Identity::savedPerson('Grace');
    $tag = Crm::tag($by, 'Partner');
    Crm::email($by, $ada->id, 'shared@example.org');
    Crm::email($by, $grace->id, 'shared@example.org'); // the same address on two People
    Crm::tagPerson($by, $grace->id, [$tag]);

    expect(directoryNames(directory($by, 'shared@example.org')))->toBe(['Ada', 'Grace'])
        ->and(directoryNames(directory($by, 'shared@example.org', $tag)))->toBe(['Grace']);
});

it('keeps Identity\'s order and paging: lower-cased name, then id, with a stable total', function () {
    $by = Crm::manager();
    foreach (['carol', 'Alice', 'bob', 'ALAN', 'Bea'] as $name) {
        Identity::savedPerson($name);
    }

    // The manager is a Person too: "Zz Manager" sorts last.
    $first = directory($by, null, null, 1, 2);
    $second = directory($by, null, null, 2, 2);
    $third = directory($by, null, null, 3, 2);

    expect(directoryNames($first))->toBe(['ALAN', 'Alice'])
        ->and(directoryNames($second))->toBe(['Bea', 'bob'])
        ->and(directoryNames($third))->toBe(['carol', Crm::MANAGER_NAME])
        ->and([$first->total, $third->total, $first->lastPage()])->toBe([6, 6, 3]);
});

it('bounds the page size at Identity\'s maximum', function () {
    $by = Crm::manager();
    Identity::savedPerson('One');

    expect(directory($by, null, null, 1, 5000)->perPage)->toBe(PeopleQuery::MAX_PER_PAGE);
});

it('runs a constant number of statements however large the page', function () {
    $by = Crm::manager();
    for ($i = 1; $i <= 30; $i++) {
        $person = Identity::savedPerson(sprintf('Person %02d', $i));
        Crm::email($by, $person->id, "p{$i}@example.org");
        Crm::tagPerson($by, $person->id, [Crm::tag($by, "Tag {$i}")]);
    }

    $count = function (int $perPage) use ($by): int {
        $statements = 0;
        Event::listen(QueryExecuted::class, function () use (&$statements): void {
            $statements++;
        });
        directory($by, 'example.org', null, 1, $perPage);

        return $statements;
    };
    $small = $count(3);
    $large = $count(30);

    expect($small)->toBe($large)->and($small)->toBeLessThanOrEqual(12); // the authorizer's reads, a contact search, a count, a page, methods, tags
});

it('refuses a text search that selects more People than a search can compose', function () {
    $by = Crm::manager();
    $now = '2026-10-01 12:00:00';
    $people = [];
    $methods = [];
    for ($i = 0; $i <= PeopleQuery::MAX_ID_SET; $i++) {
        $id = PersonId::generate();
        $people[] = ['id' => $id->value, 'display_name' => "Bulk {$i}", 'created_at' => $now, 'updated_at' => $now];
        $methods[] = ['id' => strtolower((string) Str::ulid()), 'person_id' => $id->value, 'kind' => 'email', 'value' => "b{$i}@bulk.example", 'search_value' => "b{$i}@bulk.example", 'label' => null, 'primary_kind' => null, 'created_at' => $now, 'updated_at' => $now];
    }
    foreach (array_chunk($people, 1000) as $chunk) {
        DB::table('people')->insert($chunk);
    }
    foreach (array_chunk($methods, 1000) as $chunk) {
        DB::table('contact_methods')->insert($chunk);
    }

    expect(fn () => directory($by, '@bulk.example'))->toThrow(SearchTooBroad::class);
});

it('refuses the directory to an Actor without crm.people.view', function () {
    $stranger = Access::actorFor(Identity::savedActiveAccount('stranger@example.org'));
    Identity::savedPerson('Ada');

    expect(fn () => directory($stranger))->toThrow(AccessDenied::class);
});
