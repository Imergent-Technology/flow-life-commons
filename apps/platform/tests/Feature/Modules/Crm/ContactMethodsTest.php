<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Crm\Application\AddContactMethod;
use App\Modules\Crm\Application\ContactMethodNotFound;
use App\Modules\Crm\Application\DuplicateContactMethod;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\RemoveContactMethod;
use App\Modules\Crm\Application\UnknownPerson;
use App\Modules\Crm\Application\UpdateContactMethod;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Crm;
use Tests\Support\Identity;

/*
 * Contact methods: add, change and remove, and the one-primary-per-kind rule, through the real use cases (ADR 0034).
 * Runs on MariaDB and PostgreSQL.
 */

/** @return list<array{string, bool}> value and primary flag of a Person's methods of one kind, in storage order */
function methodsOf(PersonId $person, string $kind): array
{
    $methods = [];
    foreach (DB::table('contact_methods')->where('person_id', $person->value)->where('kind', $kind)->orderBy('created_at')->orderBy('id')->get() as $row) {
        assert(is_string($row->value));
        $methods[] = [$row->value, $row->primary_kind !== null];
    }

    return $methods;
}

it('makes the first method of a kind its primary, whatever was asked', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');

    $first = Crm::email($by, $ada->id, 'ada@example.org', primary: false);
    $second = Crm::email($by, $ada->id, 'ada.work@example.org');

    expect($first->isPrimary)->toBeTrue()
        ->and($second->isPrimary)->toBeFalse()
        ->and(methodsOf($ada->id, 'email'))->toBe([['ada@example.org', true], ['ada.work@example.org', false]]);
});

it('demotes the old primary when a later method is added as primary, so there is still exactly one', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Crm::email($by, $ada->id, 'old@example.org');

    Crm::email($by, $ada->id, 'new@example.org', primary: true);

    expect(methodsOf($ada->id, 'email'))->toBe([['old@example.org', false], ['new@example.org', true]])
        ->and(DB::table('contact_methods')->where('person_id', $ada->id->value)->whereNotNull('primary_kind')->count())->toBe(1);
});

it('keeps a primary per kind independently: an email and a phone number are each primary', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');

    Crm::email($by, $ada->id, 'ada@example.org');
    Crm::phone($by, $ada->id, '555 010 0100');

    expect(DB::table('contact_methods')->where('person_id', $ada->id->value)->whereNotNull('primary_kind')->count())->toBe(2);
});

it('allows the same value for different People, and refuses it twice for one', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $grace = Identity::savedPerson('Grace');
    Crm::email($by, $ada->id, 'family@example.org');

    Crm::email($by, $grace->id, 'family@example.org'); // shared household address: legitimate

    expect(fn () => Crm::email($by, $ada->id, 'Family@Example.ORG'))->toThrow(DuplicateContactMethod::class) // same mailbox, written differently
        ->and(DB::table('contact_methods')->where('search_value', 'family@example.org')->count())->toBe(2);
});

it('treats phone numbers with the same digits as the same number for one Person', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Crm::phone($by, $ada->id, '(555) 010-0100');

    expect(fn () => Crm::phone($by, $ada->id, '555.010.0100'))->toThrow(DuplicateContactMethod::class);
    Crm::phone($by, $ada->id, '+1 555 010 0100'); // a leading plus is a different search form: not guessed to be the same
    expect(DB::table('contact_methods')->where('person_id', $ada->id->value)->count())->toBe(2);
});

it('creates the Person\'s CRM profile row only when there is CRM data to hold', function () {
    $by = Crm::manager();
    $touched = Identity::savedPerson('Touched');
    Identity::savedPerson('Untouched');

    expect(DB::table('contact_profiles')->count())->toBe(0);
    Crm::email($by, $touched->id, 'touched@example.org');

    expect(DB::table('contact_profiles')->pluck('person_id')->all())->toBe([$touched->id->value]);
});

it('refuses invalid input and writes nothing', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');

    expect(fn () => Crm::email($by, $ada->id, 'not an email'))->toThrow(InvalidContactInput::class)
        ->and(fn () => Crm::phone($by, $ada->id, '12'))->toThrow(InvalidContactInput::class)
        ->and(DB::table('contact_methods')->count())->toBe(0);
});

it('changes a method\'s value, label and primary flag', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $first = Crm::email($by, $ada->id, 'a1@example.org');
    $second = Crm::email($by, $ada->id, 'a2@example.org');
    $update = app(UpdateContactMethod::class);

    $changed = $update($by, $ada->id, $second->id, ['value' => 'A2@Example.org', 'label' => ' work ', 'is_primary' => true]);

    expect($changed->value)->toBe('A2@Example.org')->and($changed->label)->toBe('work')->and($changed->isPrimary)->toBeTrue()
        ->and(methodsOf($ada->id, 'email'))->toBe([['a1@example.org', false], ['A2@Example.org', true]]);

    $update($by, $ada->id, $first->id, ['is_primary' => true]);
    expect(methodsOf($ada->id, 'email'))->toBe([['a1@example.org', true], ['A2@Example.org', false]]);
});

it('leaves a field that is not sent alone, and clears a label sent as null', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $method = Crm::method($by, $ada->id, ContactMethodKind::Email, 'a@example.org', label: 'home');

    $unchanged = app(UpdateContactMethod::class)($by, $ada->id, $method->id, ['is_primary' => true]);
    $cleared = app(UpdateContactMethod::class)($by, $ada->id, $method->id, ['label' => null]);

    expect($unchanged->label)->toBe('home')->and($cleared->label)->toBeNull()->and($cleared->value)->toBe('a@example.org');
});

it('refuses to un-set the primary of a kind: exactly one stays primary while any method of the kind exists', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $first = Crm::email($by, $ada->id, 'a1@example.org');
    $second = Crm::email($by, $ada->id, 'a2@example.org');

    // Another method of the kind remains: the current primary cannot simply be demoted.
    $demote = fn () => app(UpdateContactMethod::class)($by, $ada->id, $first->id, ['is_primary' => false, 'label' => 'ignored']);
    expect($demote)->toThrow(InvalidContactInput::class, 'Make another contact method primary instead.');
    try {
        $demote();
    } catch (InvalidContactInput $e) {
        expect($e->field)->toBe('is_primary');
    }
    expect(methodsOf($ada->id, 'email'))->toBe([['a1@example.org', true], ['a2@example.org', false]]);
    expect(DB::table('contact_methods')->where('id', $first->id->value)->value('label'))->toBeNull(); // nothing was partly applied

    // The only method of its kind is the primary, and stays so.
    $solo = Crm::phone($by, $ada->id, '555 010 0100');
    expect(fn () => app(UpdateContactMethod::class)($by, $ada->id, $solo->id, ['is_primary' => false]))->toThrow(InvalidContactInput::class);
    expect(methodsOf($ada->id, 'phone'))->toBe([['555 010 0100', true]]);

    // Promoting the other one is how the primary moves: atomic, still exactly one.
    app(UpdateContactMethod::class)($by, $ada->id, $second->id, ['is_primary' => true]);
    expect(methodsOf($ada->id, 'email'))->toBe([['a1@example.org', false], ['a2@example.org', true]]);

    // Sending the value it already has is not a demotion, and a non-primary may be sent false.
    app(UpdateContactMethod::class)($by, $ada->id, $second->id, ['is_primary' => true, 'label' => 'work']);
    app(UpdateContactMethod::class)($by, $ada->id, $first->id, ['is_primary' => false, 'label' => 'home']);
    expect(methodsOf($ada->id, 'email'))->toBe([['a1@example.org', false], ['a2@example.org', true]]);
});

it('refuses to change a method into one the Person already has', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Crm::email($by, $ada->id, 'a1@example.org');
    $second = Crm::email($by, $ada->id, 'a2@example.org');

    expect(fn () => app(UpdateContactMethod::class)($by, $ada->id, $second->id, ['value' => 'A1@example.org']))->toThrow(DuplicateContactMethod::class);
    // ...but changing only the case of its own value is not a duplicate of itself.
    expect(app(UpdateContactMethod::class)($by, $ada->id, $second->id, ['value' => 'A2@example.org'])->value)->toBe('A2@example.org');
});

it('does not let one Person\'s method be changed or removed through another Person', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $grace = Identity::savedPerson('Grace');
    $method = Crm::email($by, $ada->id, 'a@example.org');

    expect(fn () => app(UpdateContactMethod::class)($by, $grace->id, $method->id, ['label' => 'mine']))->toThrow(ContactMethodNotFound::class)
        ->and(fn () => app(RemoveContactMethod::class)($by, $grace->id, $method->id))->toThrow(ContactMethodNotFound::class)
        ->and(fn () => app(UpdateContactMethod::class)($by, $ada->id, ContactMethodId::generate(), ['label' => 'x']))->toThrow(ContactMethodNotFound::class)
        ->and(DB::table('contact_methods')->where('id', $method->id->value)->value('label'))->toBeNull();
});

it('promotes the earliest remaining method of the kind when the primary is removed', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $primary = Crm::email($by, $ada->id, 'a1@example.org');
    Crm::email($by, $ada->id, 'a2@example.org');
    Crm::email($by, $ada->id, 'a3@example.org');
    Crm::phone($by, $ada->id, '555 010 0100');

    app(RemoveContactMethod::class)($by, $ada->id, $primary->id);

    expect(methodsOf($ada->id, 'email'))->toBe([['a2@example.org', true], ['a3@example.org', false]])
        ->and(methodsOf($ada->id, 'phone'))->toBe([['555 010 0100', true]]); // another kind is untouched
});

it('removes a non-primary method and the last method without fuss', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Crm::email($by, $ada->id, 'a1@example.org');
    $second = Crm::email($by, $ada->id, 'a2@example.org');

    app(RemoveContactMethod::class)($by, $ada->id, $second->id);

    expect(methodsOf($ada->id, 'email'))->toBe([['a1@example.org', true]]);
});

it('refuses an unknown Person from every operation', function () {
    $by = Crm::manager();
    $nobody = PersonId::generate();

    expect(fn () => app(AddContactMethod::class)($by, $nobody, new NewContactMethod(ContactMethodKind::Email, 'a@example.org')))->toThrow(UnknownPerson::class)
        ->and(fn () => app(UpdateContactMethod::class)($by, $nobody, ContactMethodId::generate(), ['label' => 'x']))->toThrow(UnknownPerson::class)
        ->and(fn () => app(RemoveContactMethod::class)($by, $nobody, ContactMethodId::generate()))->toThrow(UnknownPerson::class)
        ->and(DB::table('contact_profiles')->count())->toBe(0);
});

it('refuses every operation to an Actor without crm.people.manage, before touching anything', function () {
    $stranger = Access::actorFor(Identity::savedActiveAccount('stranger@example.org')); // signed-in, no role
    $ada = Identity::savedPerson('Ada');
    $existing = Crm::email(Crm::manager(), $ada->id, 'a@example.org');

    expect(fn () => app(AddContactMethod::class)($stranger, $ada->id, new NewContactMethod(ContactMethodKind::Email, 'b@example.org')))->toThrow(AccessDenied::class)
        ->and(fn () => app(UpdateContactMethod::class)($stranger, $ada->id, $existing->id, ['label' => 'x']))->toThrow(AccessDenied::class)
        ->and(fn () => app(RemoveContactMethod::class)($stranger, $ada->id, $existing->id))->toThrow(AccessDenied::class)
        ->and(DB::table('contact_methods')->count())->toBe(1);
});

it('records when a method was made and changed, in UTC', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Carbon::setTestNow('2026-10-01 09:00:00');
    $method = Crm::email($by, $ada->id, 'a@example.org');
    Carbon::setTestNow('2026-10-02 10:30:00');
    app(UpdateContactMethod::class)($by, $ada->id, $method->id, ['label' => 'home']);

    $row = DB::table('contact_methods')->where('id', $method->id->value)->first();
    assert($row instanceof stdClass);
    expect($row->created_at)->toBe('2026-10-01 09:00:00')
        ->and($row->updated_at)->toBe('2026-10-02 10:30:00');
});
