<?php

declare(strict_types=1);

use App\Modules\Crm\Application\AddContactMethod;
use App\Modules\Crm\Application\GetPersonRecord;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\ProfileChanges;
use App\Modules\Crm\Application\RegisterContact;
use App\Modules\Crm\Application\RemoveContactMethod;
use App\Modules\Crm\Application\UpdateContactMethod;
use App\Modules\Crm\Application\UpdatePerson;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Shared\Domain\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Crm;
use Tests\Support\Identity;

/*
 * Contact-method provenance (ADR 0038, P9): `contact_methods.updated_by_account_id` names the Account that last changed a
 * method's value, label or primary flag. Every row a path changes is stamped, side-effect rows included (a demoted primary, a
 * promoted successor), and a row that does not change is never stamped. These tests are the regression for each row of
 * P9's table that CRM owns. The delegated writers are in DelegatedSeamTest.
 */

function methodStamp(string $methodId): ?string
{
    $stamp = DB::table('contact_methods')->where('id', $methodId)->value('updated_by_account_id');
    assert($stamp === null || is_string($stamp));

    return $stamp;
}

/** Plants a method as it was before provenance existed: NULL stamp. */
function legacyMethod(string $personId, string $kind, string $value, string $searchValue, bool $primary): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('contact_methods')->insert([
        'id' => $id,
        'person_id' => $personId,
        'kind' => $kind,
        'value' => $value,
        'search_value' => $searchValue,
        'label' => null,
        'primary_kind' => $primary ? $kind : null,
        'created_at' => '2026-09-01 10:00:00',
        'updated_at' => '2026-09-01 10:00:00',
        'updated_by_account_id' => null,
    ]);

    return $id;
}

function actorAccount(Actor $actor): string
{
    return $actor->accountId->value;
}

it('stamps every method a registration creates with the registering Account', function () {
    $ada = Crm::manager('ada.manager@example.org');

    $record = app(RegisterContact::class)($ada, 'Registered', null, null, [
        new NewContactMethod(ContactMethodKind::Email, 'reg@example.org'),
        new NewContactMethod(ContactMethodKind::Phone, '+1 555 010 0100'),
        new NewContactMethod(ContactMethodKind::Email, 'second@example.org'),
    ], false);

    $stamps = DB::table('contact_methods')->where('person_id', $record->person->id->value)->pluck('updated_by_account_id')->all();
    expect($stamps)->toHaveCount(3)->and(array_unique($stamps))->toBe([actorAccount($ada)]);
});

it('stamps an added method, and the former primary an added primary demotes, with the adding Account', function () {
    $first = Crm::manager('first.manager@example.org');
    $second = Crm::manager('second.manager@example.org');
    $person = Identity::savedPerson('Person');
    $original = Crm::email($first, $person->id, 'one@example.org');
    expect(methodStamp($original->id->value))->toBe(actorAccount($first));

    $added = app(AddContactMethod::class)($second, $person->id, new NewContactMethod(ContactMethodKind::Email, 'two@example.org', null, true));

    expect(methodStamp($added->id->value))->toBe(actorAccount($second))
        ->and(methodStamp($original->id->value))->toBe(actorAccount($second)) // demoted as a side effect
        ->and(DB::table('contact_methods')->where('id', $original->id->value)->value('primary_kind'))->toBeNull();
});

it('stamps a changed value and a changed label, and nothing when the request changes nothing', function () {
    $first = Crm::manager('first.manager@example.org');
    $second = Crm::manager('second.manager@example.org');
    $person = Identity::savedPerson('Person');
    $method = Crm::method($first, $person->id, ContactMethodKind::Phone, '+1 555 010 0100', true, 'home');
    $bystander = Crm::email($first, $person->id, 'bystander@example.org');
    $before = DB::table('contact_methods')->where('id', $method->id->value)->value('updated_at');

    app(UpdateContactMethod::class)($second, $person->id, $method->id, ['value' => '+1 555 010 0100', 'label' => 'home']);
    expect(methodStamp($method->id->value))->toBe(actorAccount($first))
        ->and(DB::table('contact_methods')->where('id', $method->id->value)->value('updated_at'))->toBe($before);

    app(UpdateContactMethod::class)($second, $person->id, $method->id, ['label' => 'work']);
    expect(methodStamp($method->id->value))->toBe(actorAccount($second));

    app(UpdateContactMethod::class)($first, $person->id, $method->id, ['value' => '+1 555 010 0199']);
    expect(methodStamp($method->id->value))->toBe(actorAccount($first))
        ->and(methodStamp($bystander->id->value))->toBe(actorAccount($first)); // never touched, still its creator's
});

it('stamps both the promoted method and the demoted primary when an update changes the primary', function () {
    $first = Crm::manager('first.manager@example.org');
    $second = Crm::manager('second.manager@example.org');
    $person = Identity::savedPerson('Person');
    $primary = Crm::email($first, $person->id, 'primary@example.org');
    $other = Crm::email($first, $person->id, 'other@example.org');

    app(UpdateContactMethod::class)($second, $person->id, $other->id, ['is_primary' => true]);

    expect(methodStamp($other->id->value))->toBe(actorAccount($second))
        ->and(methodStamp($primary->id->value))->toBe(actorAccount($second))
        ->and(DB::table('contact_methods')->where('id', $primary->id->value)->value('primary_kind'))->toBeNull();
});

it('stamps the method that removing the primary promotes, and no other', function () {
    $first = Crm::manager('first.manager@example.org');
    $second = Crm::manager('second.manager@example.org');
    $person = Identity::savedPerson('Person');
    $primary = Crm::email($first, $person->id, 'primary@example.org');
    $next = Crm::email($first, $person->id, 'next@example.org');
    $later = Crm::email($first, $person->id, 'later@example.org');
    $phone = Crm::phone($first, $person->id, '+1 555 010 0100');

    app(RemoveContactMethod::class)($second, $person->id, $primary->id);

    expect(DB::table('contact_methods')->where('id', $primary->id->value)->exists())->toBeFalse()
        ->and(DB::table('contact_methods')->where('id', $next->id->value)->value('primary_kind'))->toBe('email')
        ->and(methodStamp($next->id->value))->toBe(actorAccount($second))
        ->and(methodStamp($later->id->value))->toBe(actorAccount($first))
        ->and(methodStamp($phone->id->value))->toBe(actorAccount($first));

    // Removing a method that is not the primary promotes nothing and stamps nothing.
    app(RemoveContactMethod::class)($first, $person->id, $later->id);
    expect(methodStamp($next->id->value))->toBe(actorAccount($second));
});

it('leaves a legacy NULL stamp NULL until a row actually changes', function () {
    $manager = Crm::manager();
    $person = Identity::savedPerson('Legacy');
    $primary = legacyMethod($person->id->value, 'email', 'old@example.org', 'old@example.org', true);
    $other = legacyMethod($person->id->value, 'email', 'older@example.org', 'older@example.org', false);
    $phone = legacyMethod($person->id->value, 'phone', '+1 555 010 0100', '+15550100100', true);

    // A change to one row stamps that row only: the legacy rows beside it stay NULL.
    app(UpdateContactMethod::class)($manager, $person->id, ContactMethodId::fromString($phone), ['label' => 'mobile']);
    expect(methodStamp($phone))->toBe(actorAccount($manager))
        ->and(methodStamp($primary))->toBeNull()
        ->and(methodStamp($other))->toBeNull();

    // Reading the Person through CRM changes nothing.
    app(GetPersonRecord::class)($manager, $person->id);
    expect(methodStamp($primary))->toBeNull()->and(methodStamp($other))->toBeNull();

    // A side-effect demotion DOES change the legacy primary, so it is stamped; the row that did not change is not.
    app(UpdateContactMethod::class)($manager, $person->id, ContactMethodId::fromString($other), ['is_primary' => true]);
    expect(methodStamp($other))->toBe(actorAccount($manager))
        ->and(methodStamp($primary))->toBe(actorAccount($manager));
});

it('never infers a method\'s provenance from the profile, and a profile edit stamps no method', function () {
    $first = Crm::manager('first.manager@example.org');
    $second = Crm::manager('second.manager@example.org');
    $person = Identity::savedPerson('Profile');
    $method = Crm::email($first, $person->id, 'kept@example.org');
    $legacy = legacyMethod($person->id->value, 'phone', '+1 555 010 0100', '+15550100100', true);

    app(UpdatePerson::class)($second, $person->id, null, new ProfileChanges(['how_we_know' => 'Choir']));

    expect(DB::table('contact_profiles')->where('person_id', $person->id->value)->value('updated_by_account_id'))->toBe(actorAccount($second))
        ->and(methodStamp($method->id->value))->toBe(actorAccount($first))
        ->and(methodStamp($legacy))->toBeNull();

    // And the other way round: a contact change leaves the profile's own meaning alone.
    app(UpdateContactMethod::class)($first, $person->id, $method->id, ['label' => 'personal']);
    expect(DB::table('contact_profiles')->where('person_id', $person->id->value)->value('updated_by_account_id'))->toBe(actorAccount($second));
});
