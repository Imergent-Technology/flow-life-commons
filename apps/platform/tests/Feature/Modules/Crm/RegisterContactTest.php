<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Crm\Application\DuplicateContactMethod;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\PersonRecord;
use App\Modules\Crm\Application\PossibleDuplicate;
use App\Modules\Crm\Application\RegisterContact;
use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\Access;
use Tests\Support\Crm;
use Tests\Support\Identity;

/*
 * RegisterContact: a Person with their first CRM information, in one transaction (ADR 0034). Possible duplicates are
 * advice, never a merge. Runs on MariaDB and PostgreSQL.
 */

/** @param  list<NewContactMethod>  $methods */
function register(string $name, array $methods = [], ?string $how = null, ?string $affiliation = null, bool $confirm = false, ?Actor $by = null): PersonRecord
{
    return app(RegisterContact::class)($by ?? Crm::manager(), $name, $how, $affiliation, $methods, $confirm);
}

function email(string $value, bool $primary = false): NewContactMethod
{
    return new NewContactMethod(ContactMethodKind::Email, $value, null, $primary);
}

it('creates one Identity Person and the CRM data about it, with no Account, Membership or role', function () {
    Crm::manager(); // the manager is a Person too: create it before counting
    $people = DB::table('people')->count();

    $record = register('Mia Maker', [email('mia@example.org'), new NewContactMethod(ContactMethodKind::Phone, '555 010 0100')], 'Met at the market', 'Makers Guild');

    expect(DB::table('people')->count())->toBe($people + 1)
        ->and($record->person->displayName)->toBe('Mia Maker')
        ->and($record->profile?->howWeKnow)->toBe('Met at the market')
        ->and($record->profile?->affiliation)->toBe('Makers Guild')
        ->and(array_map(fn ($m): string => $m->value, $record->contactMethods))->toBe(['mia@example.org', '555 010 0100'])
        ->and(array_map(fn ($m): bool => $m->isPrimary, $record->contactMethods))->toBe([true, true])
        // CRM's rows reference the SAME Person Identity created:
        ->and(DB::table('contact_methods')->pluck('person_id')->unique()->values()->all())->toBe([$record->person->id->value])
        ->and(DB::table('contact_profiles')->value('person_id'))->toBe($record->person->id->value)
        // ...and nothing about authority came into being:
        ->and(DB::table('accounts')->where('person_id', $record->person->id->value)->count())->toBe(0)
        ->and(DB::table('role_assignments')->where('person_id', $record->person->id->value)->count())->toBe(0)
        ->and(DB::table('membership_grants')->where('person_id', $record->person->id->value)->count())->toBe(0);
});

it('creates a Person with no CRM information at all, and no CRM row', function () {
    $record = register('Just A Name');

    expect($record->profile)->toBeNull()->and($record->contactMethods)->toBe([])->and($record->tags)->toBe([])
        ->and(DB::table('contact_profiles')->where('person_id', $record->person->id->value)->exists())->toBeFalse();
});

it('advises of a possible duplicate by CRM email, and creates nothing', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelace');
    Crm::email($by, $ada->id, 'ada@example.org');
    $before = DB::table('people')->count();

    try {
        register('Someone Else Entirely', [email('ADA@example.org')], by: $by);
        Assert::fail('expected a possible duplicate');
    } catch (PossibleDuplicate $e) {
        expect($e->candidates)->toHaveCount(1)
            ->and($e->candidates[0]->person->id->equals($ada->id))->toBeTrue()
            ->and($e->candidates[0]->person->displayName)->toBe('Ada Lovelace')
            ->and($e->candidates[0]->matchedOn)->toBe(['email']);
    }
    expect(DB::table('people')->count())->toBe($before)->and(DB::table('contact_methods')->count())->toBe(1);
});

it('advises of a possible duplicate by display name, ignoring case', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelace');

    try {
        register('  ADA lovelace ', by: $by);
        Assert::fail('expected a possible duplicate');
    } catch (PossibleDuplicate $e) {
        expect($e->candidates[0]->person->id->equals($ada->id))->toBeTrue()->and($e->candidates[0]->matchedOn)->toBe(['display_name']);
    }
});

it('names every reason a candidate looks the same, and each candidate once', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelace');
    Crm::email($by, $ada->id, 'ada@example.org');

    try {
        register('Ada Lovelace', [email('ada@example.org')], by: $by);
        Assert::fail('expected a possible duplicate');
    } catch (PossibleDuplicate $e) {
        expect($e->candidates)->toHaveCount(1)->and($e->candidates[0]->matchedOn)->toEqualCanonicalizing(['email', 'display_name']);
    }
});

it('does not take a name that merely CONTAINS the new one as a duplicate, nor a shared phone number', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelace Jr');
    Crm::phone($by, $ada->id, '555 010 0100');

    $record = register('Ada Lovelace', [new NewContactMethod(ContactMethodKind::Phone, '555 010 0100')], by: $by);

    expect($record->person->displayName)->toBe('Ada Lovelace');
});

it('does NOT consult Account login emails: an Account\'s address is not advice to a CRM user (accepted Phase 1 behaviour)', function () {
    $by = Crm::manager();
    Identity::savedActiveAccount('login.address@example.org', name: 'Account Holder');

    $record = register('Different Name', [email('login.address@example.org')], by: $by);

    expect($record->person->displayName)->toBe('Different Name'); // no advice, and so nothing about the Account was disclosed
});

it('registers anyway, as a distinct Person, when told to; it never merges or adopts', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelace');
    Crm::email($by, $ada->id, 'ada@example.org');

    $second = register('Ada Lovelace', [email('ada@example.org')], confirm: true, by: $by);

    expect($second->person->id->equals($ada->id))->toBeFalse()
        ->and(DB::table('people')->where('display_name', 'Ada Lovelace')->count())->toBe(2)
        ->and(DB::table('contact_methods')->where('search_value', 'ada@example.org')->count())->toBe(2) // not unique across People
        ->and(DB::table('contact_methods')->where('person_id', $ada->id->value)->count())->toBe(1);       // the existing Person is untouched
});

it('checks the server\'s own current state, so a stale all-clear from a screen cannot let a duplicate through', function () {
    $by = Crm::manager();
    // The screen "looked" and found nobody; then somebody else registered the same email before this request arrived.
    Crm::email($by, Identity::savedPerson('Early Bird')->id, 'late@example.org');

    expect(fn () => register('Latecomer', [email('late@example.org')], by: $by))->toThrow(PossibleDuplicate::class);
});

it('still validates when told the Person is distinct: confirming skips only the advice', function () {
    expect(fn () => register('Bad Mail', [email('not an email')], confirm: true))->toThrow(InvalidContactInput::class)
        ->and(fn () => register('   ', confirm: true))->toThrow(InvalidContactInput::class);
});

it('refuses the same method twice in one request, before creating anyone', function () {
    Crm::manager();
    $before = DB::table('people')->count();

    expect(fn () => register('Twice', [email('a@example.org'), email('A@Example.org')]))->toThrow(DuplicateContactMethod::class)
        ->and(DB::table('people')->count())->toBe($before);
});

it('makes the first method of each kind the primary, and lets one be asked for', function () {
    $record = register('Mia', [email('first@example.org'), email('second@example.org', primary: true), email('third@example.org')]);

    expect(array_map(fn ($m): array => [$m->value, $m->isPrimary], $record->contactMethods))
        ->toBe([['first@example.org', false], ['second@example.org', true], ['third@example.org', false]]);
});

it('rolls the new Person back with it when a CRM write fails after the Person was created', function () {
    $by = Crm::manager();
    $before = DB::table('people')->count();
    $failing = new class(app(ContactMethodRepository::class)) implements ContactMethodRepository
    {
        public function __construct(private ContactMethodRepository $real) {}

        public function find(ContactMethodId $id): ?ContactMethod
        {
            return $this->real->find($id);
        }

        public function forPerson(PersonId $personId): array
        {
            return $this->real->forPerson($personId);
        }

        public function forPeople(array $personIds): array
        {
            return $this->real->forPeople($personIds);
        }

        public function add(ContactMethod $method): void
        {
            throw new RuntimeException('contact store unavailable');
        }

        public function save(ContactMethod $method): void
        {
            $this->real->save($method);
        }

        public function remove(ContactMethodId $id): void
        {
            $this->real->remove($id);
        }

        public function clearPrimary(PersonId $personId, ContactMethodKind $kind): void
        {
            $this->real->clearPrimary($personId, $kind);
        }

        public function personIdsWithSearchValue(ContactMethodKind $kind, string $searchValue): array
        {
            return $this->real->personIdsWithSearchValue($kind, $searchValue);
        }

        public function personIdsMatching(string $text, int $limit): array
        {
            return $this->real->personIdsMatching($text, $limit);
        }
    };
    app()->instance(ContactMethodRepository::class, $failing);

    expect(fn () => register('Doomed', [email('doomed@example.org')], by: $by))->toThrow(RuntimeException::class, 'contact store unavailable');

    expect(DB::table('people')->count())->toBe($before)->and(DB::table('people')->where('display_name', 'Doomed')->exists())->toBeFalse()
        ->and(DB::table('contact_profiles')->count())->toBe(0);
});

it('refuses an Actor without crm.people.manage before looking at anything', function () {
    $stranger = Access::actorFor(Identity::savedActiveAccount('stranger@example.org'));
    $before = DB::table('people')->count();

    expect(fn () => register('Nope', by: $stranger))->toThrow(AccessDenied::class)
        ->and(DB::table('people')->count())->toBe($before);
});
