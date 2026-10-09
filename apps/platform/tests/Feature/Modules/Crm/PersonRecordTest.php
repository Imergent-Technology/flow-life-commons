<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Role;
use App\Modules\Crm\Application\GetPersonRecord;
use App\Modules\Crm\Application\ProfileChanges;
use App\Modules\Crm\Application\UnknownPerson;
use App\Modules\Crm\Application\UpdatePerson;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Identity\Application\RenamePerson;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Crm;
use Tests\Support\Identity;
use Tests\Support\Membership;

/*
 * Reading what CRM holds about a Person, and correcting their name and profile (ADR 0034). The name is Identity's:
 * CRM checks crm.people.manage and then calls Identity's RenamePerson. Runs on MariaDB and PostgreSQL.
 */

it('reads a Person with nothing in CRM as a name and empty CRM data', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');

    $record = app(GetPersonRecord::class)($by, $ada->id);

    expect($record->person->displayName)->toBe('Ada')->and($record->profile)->toBeNull()
        ->and($record->contactMethods)->toBe([])->and($record->tags)->toBe([]);
});

it('reads the profile, methods and tags together', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    app(UpdatePerson::class)($by, $ada->id, null, new ProfileChanges(['how_we_know' => 'Workshop', 'affiliation' => 'Guild']));
    Crm::email($by, $ada->id, 'a@example.org');
    Crm::tagPerson($by, $ada->id, [Crm::tag($by, 'Artist')]);

    $record = app(GetPersonRecord::class)($by, $ada->id);

    expect($record->profile?->howWeKnow)->toBe('Workshop')
        ->and(array_map(fn ($m): string => $m->value, $record->contactMethods))->toBe(['a@example.org'])
        ->and(array_map(fn ($t): string => $t->name, $record->tags))->toBe(['Artist']);
});

it('composes nothing from Membership, Accounts or access into the record', function () {
    $by = Crm::manager();
    $account = Identity::savedActiveAccount('operator@example.org', name: 'Operator');
    Access::grant($account, Role::GuardianFull);
    Membership::savedGrant($account->personId);

    $record = app(GetPersonRecord::class)($by, $account->personId);

    $properties = array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass($record))->getProperties());
    expect($properties)->toEqualCanonicalizing(['person', 'profile', 'contactMethods', 'tags'])
        ->and(array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass($record->person))->getProperties()))->toEqualCanonicalizing(['id', 'displayName']);
});

it('refuses to read a Person who does not exist, or to an Actor without crm.people.view', function () {
    $by = Crm::manager();
    $stranger = Access::actorFor(Identity::savedActiveAccount('stranger@example.org'));
    $ada = Identity::savedPerson('Ada');

    expect(fn () => app(GetPersonRecord::class)($by, PersonId::generate()))->toThrow(UnknownPerson::class)
        ->and(fn () => app(GetPersonRecord::class)($stranger, $ada->id))->toThrow(AccessDenied::class);
});

it('corrects a name through Identity, which records person.renamed, and needs no recent verification', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelase');

    $result = app(UpdatePerson::class)($by, $ada->id, 'Ada Lovelace', new ProfileChanges);

    expect($result->person->displayName)->toBe('Ada Lovelace')
        ->and(DB::table('people')->where('id', $ada->id->value)->value('display_name'))->toBe('Ada Lovelace')
        ->and($result->profile)->toBeNull()
        ->and(DB::table('contact_profiles')->count())->toBe(0); // a name correction creates no CRM row
    $events = Identity::events('person.renamed');
    expect($events)->toHaveCount(1)
        ->and($events[0]->subject_person_id)->toBe($ada->id->value)
        ->and($events[0]->actor_account_id)->toBe($by->accountId->value)
        ->and(Identity::context($events[0]))->toBe(['changed' => 'display_name']);
});

it('does not touch the Person\'s name when only the profile changes, and records no rename', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');

    app(UpdatePerson::class)($by, $ada->id, null, new ProfileChanges(['affiliation' => 'Guild']));

    expect(Identity::events('person.renamed'))->toBe([])
        ->and(DB::table('people')->where('id', $ada->id->value)->value('updated_at'))->toBe('2026-09-19 12:00:00');
});

it('records an invalid name as a CRM input error, changing nothing', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');

    expect(fn () => app(UpdatePerson::class)($by, $ada->id, '   ', new ProfileChanges(['affiliation' => 'Guild'])))->toThrow(InvalidContactInput::class);

    expect(DB::table('people')->where('id', $ada->id->value)->value('display_name'))->toBe('Ada')
        ->and(DB::table('contact_profiles')->count())->toBe(0); // the profile change rolled back with the failed rename
});

it('changes only the profile fields sent, clears one set to null, and records who and when', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Carbon::setTestNow('2026-10-01 09:00:00');
    app(UpdatePerson::class)($by, $ada->id, null, new ProfileChanges(['how_we_know' => 'Workshop', 'affiliation' => 'Guild']));
    Carbon::setTestNow('2026-10-02 10:00:00');

    $result = app(UpdatePerson::class)($by, $ada->id, null, new ProfileChanges(['affiliation' => null]));

    expect($result->profile?->howWeKnow)->toBe('Workshop')->and($result->profile?->affiliation)->toBeNull();
    $row = DB::table('contact_profiles')->first();
    assert($row instanceof stdClass);
    expect($row->updated_by_account_id)->toBe($by->accountId->value)
        ->and($row->created_at)->toBe('2026-10-01 09:00:00')
        ->and($row->updated_at)->toBe('2026-10-02 10:00:00')
        ->and(DB::table('security_events')->where('type', 'like', 'crm%')->count())->toBe(0); // routine edits are not security events
});

it('refuses an unknown Person and an Actor without crm.people.manage', function () {
    $by = Crm::manager();
    $stranger = Access::actorFor(Identity::savedActiveAccount('stranger@example.org'));
    $ada = Identity::savedPerson('Ada');

    expect(fn () => app(UpdatePerson::class)($by, PersonId::generate(), 'Nobody', new ProfileChanges))->toThrow(UnknownPerson::class)
        ->and(fn () => app(UpdatePerson::class)($stranger, $ada->id, 'Hijack', new ProfileChanges))->toThrow(AccessDenied::class)
        ->and(DB::table('people')->where('id', $ada->id->value)->value('display_name'))->toBe('Ada');
});

it('never writes the people table itself: a name is changed only by Identity\'s RenamePerson', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada Lovelase');
    $calls = [];
    app()->extend(RenamePerson::class, function ($rename) use (&$calls) {
        $calls[] = 'resolved';

        return $rename;
    });

    app(UpdatePerson::class)($by, $ada->id, 'Ada Lovelace', new ProfileChanges);

    expect($calls)->toBe(['resolved'])->and(Identity::events('person.renamed'))->toHaveCount(1);
});
