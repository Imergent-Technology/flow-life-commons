<?php

declare(strict_types=1);

use App\Modules\Crm\Application\Delegated\BasicsChanges;
use App\Modules\Crm\Application\Delegated\FieldChange;
use App\Modules\Crm\Application\Delegated\FindPersonCandidates;
use App\Modules\Crm\Application\Delegated\InvalidPersonBasics;
use App\Modules\Crm\Application\Delegated\PeopleMatchingContact;
use App\Modules\Crm\Application\Delegated\PersonBasicsChanged;
use App\Modules\Crm\Application\Delegated\PossibleDuplicatePerson;
use App\Modules\Crm\Application\Delegated\ReadPersonBasics;
use App\Modules\Crm\Application\Delegated\RegisterMinimalPerson;
use App\Modules\Crm\Application\Delegated\UpdatePersonBasics;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\PersonNameChanged;
use App\Modules\Identity\Application\RenamePerson;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\Crm;
use Tests\Support\Identity;

/*
 * CRM's delegated seam (ADR 0038, P1) called directly, as Relationships' use cases call it. They authorize nothing: the
 * callers' authorization and scope are covered through the relationship routes in RelationshipPeopleAccessTest. Here: the
 * exact contract of each service.
 */

it('reads the three basic details of many People in two queries, and nothing else', function () {
    $by = Crm::manager('seam.manager@example.org');
    $ids = [];
    foreach (range(1, 6) as $n) {
        $person = Identity::savedPerson("Person {$n}");
        Crm::email($by, $person->id, "person{$n}@example.org");
        Crm::email($by, $person->id, "second{$n}@example.org");
        if ($n % 2 === 0) {
            Crm::phone($by, $person->id, "+1 555 010 010{$n}");
        }
        $ids[] = $person->id;
    }

    DB::enableQueryLog();
    $basics = app(ReadPersonBasics::class)([...$ids, PersonId::generate()]);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2)
        ->and(array_keys($basics))->toEqualCanonicalizing(array_map(fn (PersonId $id): string => $id->value, $ids)) // the unknown id is absent
        ->and($basics[$ids[0]->value]->displayName)->toBe('Person 1')
        ->and($basics[$ids[0]->value]->primaryEmail)->toBe('person1@example.org')
        ->and($basics[$ids[0]->value]->primaryPhone)->toBeNull()
        ->and($basics[$ids[1]->value]->primaryPhone)->toBe('+1 555 010 0102')
        ->and(array_keys(get_object_vars($basics[$ids[1]->value])))->toBe(['id', 'displayName', 'primaryEmail', 'primaryPhone'])
        ->and(app(ReadPersonBasics::class)([]))->toBe([]);
});

it('matches primary contact values inside the given set only, and refuses a set larger than a search can compose', function () {
    $by = Crm::manager('seam.manager@example.org');
    $inside = Identity::savedPerson('Inside');
    $outside = Identity::savedPerson('Outside');
    foreach ([$inside, $outside] as $person) {
        Crm::email($by, $person->id, 'shared.primary@example.org');
    }
    Crm::email($by, $inside->id, 'only.secondary@example.org');
    $match = app(PeopleMatchingContact::class);

    expect(array_map(fn (PersonId $id): string => $id->value, $match('shared.primary', [$inside->id])))->toBe([$inside->id->value])
        ->and($match('only.secondary', [$inside->id]))->toBe([])      // not primary
        ->and($match('shared.primary', []))->toBe([])                 // an empty set is an answer, never a wildcard
        ->and($match('   ', [$inside->id]))->toBe([])
        ->and($match('shared.primary', [$outside->id])[0]->value)->toBe($outside->id->value);

    $tooMany = array_map(fn (): PersonId => PersonId::generate(), range(1, PeopleQuery::MAX_ID_SET + 1));
    expect(fn () => $match('x', $tooMany))->toThrow(InvalidArgumentException::class);
});

it('classifies a lookup query as address, phone or name, and matches exactly', function () {
    $by = Crm::manager('seam.manager@example.org');
    $ada = Identity::savedPerson('Ada Classified');
    Crm::email($by, $ada->id, 'Ada.Classified@Example.org');
    Crm::phone($by, $ada->id, '+1 555 010 0100');
    $find = app(FindPersonCandidates::class);
    $matched = fn (string $query): array => array_map(fn ($candidate): string => $candidate->matchedOn.':'.$candidate->displayName, $find($query)->candidates);

    expect($matched('ada.classified@example.org'))->toBe(['email:Ada Classified'])
        ->and($matched('ada.classified@'))->toBe([])
        ->and($matched('a@b@c'))->toBe([])
        ->and($matched('+1 555 010 0100'))->toBe(['phone:Ada Classified'])
        ->and($matched('1-555-010-0100'))->toBe(['phone:Ada Classified'])
        ->and($matched('555 0100'))->toBe([])
        ->and($matched('classified'))->toBe(['display_name:Ada Classified'])
        ->and($matched('Ada 5550100100'))->toBe([]) // letters: a name search, and no name has these digits
        ->and($find('nothing at all')->candidates)->toBe([])
        ->and($find('nothing at all')->truncated)->toBeFalse();
});

it('registers a minimal Person with primary methods stamped, and refuses an invalid one before anything is written', function () {
    $by = Crm::manager('seam.manager@example.org');
    $people = DB::table('people')->count();

    $registered = app(RegisterMinimalPerson::class)('  Minimal  ', 'minimal@example.org', '+1 555 010 0100', false, $by);
    expect($registered->displayName)->toBe('Minimal')
        ->and(DB::table('contact_methods')->where('person_id', $registered->id->value)->pluck('updated_by_account_id')->unique()->all())->toBe([$by->accountId->value])
        ->and(DB::table('contact_methods')->where('person_id', $registered->id->value)->whereNotNull('primary_kind')->count())->toBe(2)
        ->and(DB::table('accounts')->where('person_id', $registered->id->value)->exists())->toBeFalse()
        ->and(DB::table('contact_profiles')->where('person_id', $registered->id->value)->exists())->toBeTrue(); // the lock row CRM always takes

    foreach ([['Bad', 'nope', null, 'email'], ['Bad', null, '12', 'phone'], ['', null, null, 'display_name']] as [$name, $email, $phone, $field]) {
        try {
            app(RegisterMinimalPerson::class)($name, $email, $phone, false, $by);
            Assert::fail('expected a refusal');
        } catch (InvalidPersonBasics $e) {
            expect($e->field)->toBe($field);
        }
    }
    expect(DB::table('people')->count())->toBe($people + 1);

    try {
        app(RegisterMinimalPerson::class)('Minimal', null, null, false, $by);
        Assert::fail('expected duplicate advice');
    } catch (PossibleDuplicatePerson $e) {
        expect($e->candidates)->toHaveCount(1)->and($e->candidates[0]->id->value)->toBe($registered->id->value)
            ->and($e->candidates[0]->matchedOn)->toBe(['display_name']);
    }
});

it('refuses the whole update, and reports the three current values, when any expected value differs', function () {
    $by = Crm::manager('seam.manager@example.org');
    $person = Identity::savedPerson('Atomic');
    Crm::email($by, $person->id, 'atomic@example.org');
    Crm::phone($by, $person->id, '+1 555 010 0100');
    $update = app(UpdatePersonBasics::class);
    $events = DB::table('security_events')->where('type', 'person.renamed')->count();

    try {
        $update($person->id, new BasicsChanges(
            new FieldChange('Atomic', 'Atomic Renamed'),
            new FieldChange('atomic@example.org', 'changed@example.org'),
            new FieldChange('+1 555 010 0199', '+1 555 010 0100'), // stale: the stored phone is different
        ), $by);
        Assert::fail('expected a conflict');
    } catch (PersonBasicsChanged $e) {
        expect($e->current->displayName)->toBe('Atomic')
            ->and($e->current->primaryEmail)->toBe('atomic@example.org')
            ->and($e->current->primaryPhone)->toBe('+1 555 010 0100');
    }

    expect(DB::table('people')->where('id', $person->id->value)->value('display_name'))->toBe('Atomic')
        ->and(DB::table('contact_methods')->where('person_id', $person->id->value)->where('kind', 'email')->value('value'))->toBe('atomic@example.org')
        ->and(DB::table('security_events')->where('type', 'person.renamed')->count())->toBe($events);

    // The same update with the right expectations applies all three.
    $after = $update($person->id, new BasicsChanges(
        new FieldChange('Atomic', 'Atomic Renamed'),
        new FieldChange('atomic@example.org', 'changed@example.org'),
        new FieldChange('+1 555 010 0100', '+1 555 010 0199'),
    ), $by);
    expect($after->displayName)->toBe('Atomic Renamed')->and($after->primaryEmail)->toBe('changed@example.org')->and($after->primaryPhone)->toBe('+1 555 010 0199');
});

it('writes only the contact asked about: a name-only update creates no CRM profile and touches no method', function () {
    $by = Crm::manager('seam.manager@example.org');
    $person = Identity::savedPerson('Name Only');

    app(UpdatePersonBasics::class)($person->id, new BasicsChanges(new FieldChange('Name Only', 'Name Changed')), $by);

    expect(DB::table('contact_profiles')->where('person_id', $person->id->value)->exists())->toBeFalse()
        ->and(DB::table('contact_methods')->where('person_id', $person->id->value)->count())->toBe(0)
        ->and(DB::table('people')->where('id', $person->id->value)->value('display_name'))->toBe('Name Changed');
});

it('names the expected current name in a rename, compared exactly after the lock, and writes nothing on a difference', function () {
    $person = Identity::savedPerson('Exact Name');
    $by = Crm::manager('seam.manager@example.org');
    $rename = app(RenamePerson::class);
    $events = DB::table('security_events')->where('type', 'person.renamed')->count();

    foreach (['exact name', 'Exact  Name', 'Exact Name '] as $stale) {
        try {
            $rename($person->id, 'Other', $by, $stale);
            Assert::fail('expected a refusal');
        } catch (PersonNameChanged $e) {
            expect($e->currentName)->toBe('Exact Name');
        }
    }
    expect(DB::table('people')->where('id', $person->id->value)->value('display_name'))->toBe('Exact Name')
        ->and(DB::table('security_events')->where('type', 'person.renamed')->count())->toBe($events);

    expect($rename($person->id, 'Renamed', $by, 'Exact Name')->displayName)->toBe('Renamed')
        ->and(DB::table('security_events')->where('type', 'person.renamed')->count())->toBe($events + 1)
        // Without an expectation it behaves exactly as before.
        ->and($rename($person->id, 'Renamed Again', $by)->displayName)->toBe('Renamed Again')
        ->and($rename($person->id, 'Renamed Again', $by, 'Renamed Again')->displayName)->toBe('Renamed Again')
        ->and(DB::table('security_events')->where('type', 'person.renamed')->count())->toBe($events + 2); // an unchanged name records nothing
});
