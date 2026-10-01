<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Authorizer;
use App\Modules\Crm\Application\CreateTag;
use App\Modules\Crm\Application\DeleteTag;
use App\Modules\Crm\Application\DuplicateTag;
use App\Modules\Crm\Application\ListTags;
use App\Modules\Crm\Application\RenameTag;
use App\Modules\Crm\Application\SetPersonTags;
use App\Modules\Crm\Application\TagInUse;
use App\Modules\Crm\Application\TagNotFound;
use App\Modules\Crm\Application\UnknownPerson;
use App\Modules\Crm\Application\UnknownTags;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Membership\Application\GetCurrentMembership;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Crm;
use Tests\Support\Identity;

/*
 * Tags: user-managed LABELS (ADR 0034). Create, rename, delete, assign. Runs on MariaDB and PostgreSQL.
 */

/** @return list<string> */
function tagsOf(PersonId $person): array
{
    return array_values(array_map(
        static fn (mixed $name): string => is_string($name) ? $name : '',
        DB::table('contact_tag_assignments as a')->join('contact_tags as t', 't.id', '=', 'a.tag_id')
            ->where('a.person_id', $person->value)->orderBy('t.name_canonical')->pluck('t.name')->all(),
    ));
}

it('creates tags, and treats names differing only by case or spacing as one', function () {
    $by = Crm::manager();

    $lead = app(CreateTag::class)($by, '  Lead ');
    expect($lead->tag->name)->toBe('Lead')->and($lead->people)->toBe(0);

    foreach (['lead', 'LEAD', ' lead  '] as $again) {
        expect(fn () => app(CreateTag::class)($by, $again))->toThrow(DuplicateTag::class);
    }
    expect(DB::table('contact_tags')->count())->toBe(1);
});

it('refuses an invalid tag name', function (string $name) {
    expect(fn () => app(CreateTag::class)(Crm::manager(), $name))->toThrow(InvalidContactInput::class);
})->with(['empty' => '', 'blank' => '  ', 'long' => str_repeat('x', 65)]);

it('lists tags by name with how many People hold each', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $grace = Identity::savedPerson('Grace');
    $partner = Crm::tag($by, 'Partner');
    $artist = Crm::tag($by, 'Artist');
    Crm::tag($by, 'Donor');
    Crm::tagPerson($by, $ada->id, [$partner, $artist]);
    Crm::tagPerson($by, $grace->id, [$partner]);

    $list = app(ListTags::class)($by);

    expect(array_map(fn ($row): array => [$row->tag->name, $row->people], $list))->toBe([['Artist', 1], ['Donor', 0], ['Partner', 2]]);
});

it('renames a tag, and the People who hold it keep it', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $tag = Crm::tag($by, 'Lead');
    Crm::tagPerson($by, $ada->id, [$tag]);

    $renamed = app(RenameTag::class)($by, $tag, ' Warm   Lead ');

    expect($renamed->tag->name)->toBe('Warm Lead')->and($renamed->people)->toBe(1)
        ->and(tagsOf($ada->id))->toBe(['Warm Lead']);
});

it('allows changing only the case of a tag\'s own name, and refuses another tag\'s name', function () {
    $by = Crm::manager();
    $lead = Crm::tag($by, 'lead');
    Crm::tag($by, 'Partner');

    expect(app(RenameTag::class)($by, $lead, 'Lead')->tag->name)->toBe('Lead')
        ->and(fn () => app(RenameTag::class)($by, $lead, 'PARTNER'))->toThrow(DuplicateTag::class)
        ->and(fn () => app(RenameTag::class)($by, ContactTagId::generate(), 'Whatever'))->toThrow(TagNotFound::class);
});

it('sets a Person\'s tags to exactly the given set', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $lead = Crm::tag($by, 'Lead');
    $artist = Crm::tag($by, 'Artist');
    $donor = Crm::tag($by, 'Donor');
    $set = app(SetPersonTags::class);

    expect(array_map(fn ($t): string => $t->name, $set($by, $ada->id, [$lead, $artist])))->toBe(['Artist', 'Lead']);
    expect(array_map(fn ($t): string => $t->name, $set($by, $ada->id, [$artist, $donor])))->toBe(['Artist', 'Donor']); // Lead removed, Donor added
    expect(array_map(fn ($t): string => $t->name, $set($by, $ada->id, [$artist, $artist, $donor])))->toBe(['Artist', 'Donor']); // repeats are one
    expect($set($by, $ada->id, []))->toBe([])->and(tagsOf($ada->id))->toBe([]);
});

it('records who assigned a tag, and when', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    Carbon::setTestNow('2026-10-01 09:00:00');
    Crm::tagPerson($by, $ada->id, [Crm::tag($by, 'Lead')]);

    $row = DB::table('contact_tag_assignments')->first();
    assert($row instanceof stdClass);
    expect($row->assigned_by_account_id)->toBe($by->accountId->value)->and($row->assigned_at)->toBe('2026-10-01 09:00:00');
});

it('refuses a set containing a tag that does not exist, and changes nothing', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $lead = Crm::tag($by, 'Lead');
    Crm::tagPerson($by, $ada->id, [$lead]);

    expect(fn () => app(SetPersonTags::class)($by, $ada->id, [Crm::tag($by, 'Artist'), ContactTagId::generate()]))->toThrow(UnknownTags::class)
        ->and(tagsOf($ada->id))->toBe(['Lead']);
});

it('refuses to tag a Person who does not exist', function () {
    $by = Crm::manager();

    expect(fn () => app(SetPersonTags::class)($by, PersonId::generate(), [Crm::tag($by, 'Lead')]))->toThrow(UnknownPerson::class);
});

it('deletes an unused tag, and refuses to delete one that is in use without stripping it', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $used = Crm::tag($by, 'Lead');
    $unused = Crm::tag($by, 'Donor');
    Crm::tagPerson($by, $ada->id, [$used]);

    app(DeleteTag::class)($by, $unused);
    expect(fn () => app(DeleteTag::class)($by, $used))->toThrow(TagInUse::class);

    expect(DB::table('contact_tags')->pluck('name')->all())->toBe(['Lead'])
        ->and(tagsOf($ada->id))->toBe(['Lead'])
        ->and(fn () => app(DeleteTag::class)($by, $unused))->toThrow(TagNotFound::class);

    Crm::tagPerson($by, $ada->id, []);
    app(DeleteTag::class)($by, $used);
    expect(DB::table('contact_tags')->count())->toBe(0);
});

it('refuses every tag operation to an Actor without the capability', function () {
    $stranger = Access::actorFor(Identity::savedActiveAccount('stranger@example.org'));
    $ada = Identity::savedPerson('Ada');
    $tag = Crm::tag(Crm::manager(), 'Lead');

    expect(fn () => app(CreateTag::class)($stranger, 'Other'))->toThrow(AccessDenied::class)
        ->and(fn () => app(RenameTag::class)($stranger, $tag, 'Other'))->toThrow(AccessDenied::class)
        ->and(fn () => app(DeleteTag::class)($stranger, $tag))->toThrow(AccessDenied::class)
        ->and(fn () => app(SetPersonTags::class)($stranger, $ada->id, [$tag]))->toThrow(AccessDenied::class)
        ->and(fn () => app(ListTags::class)($stranger))->toThrow(AccessDenied::class);
});

it('is a label only: a tag named like a role, capability or status changes nothing about what anyone may do or be', function () {
    $by = Crm::manager();
    $mia = Identity::savedActiveAccount('mia@example.org', name: 'Mia'); // signed-in, no role, no membership
    $before = app(Authorizer::class)->capabilitiesOf(Access::actorFor($mia));
    $tags = [];
    foreach (['Guardian', 'platform_administrator', 'console.access', 'crm.people.manage', 'Member', 'Volunteer'] as $name) {
        $tags[] = Crm::tag($by, $name);
    }

    Crm::tagPerson($by, $mia->personId, $tags);

    expect(app(Authorizer::class)->capabilitiesOf(Access::actorFor($mia)))->toBe($before)->and($before)->toBe([])
        ->and(DB::table('role_assignments')->where('person_id', $mia->personId->value)->count())->toBe(0)
        ->and(DB::table('membership_grants')->count())->toBe(0)
        ->and(app(GetCurrentMembership::class)($mia->personId)->active)->toBeFalse();
});

it('has no starter tags: the vocabulary is data a Guardian creates, never seeded into production', function () {
    expect(DB::table('contact_tags')->count())->toBe(0);
});
