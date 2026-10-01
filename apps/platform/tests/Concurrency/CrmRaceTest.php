<?php

declare(strict_types=1);

use App\Modules\Crm\Application\AddContactMethod;
use App\Modules\Crm\Application\CreateTag;
use App\Modules\Crm\Application\DeleteTag;
use App\Modules\Crm\Application\DuplicateContactMethod;
use App\Modules\Crm\Application\DuplicateTag;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\RegisterContact;
use App\Modules\Crm\Application\SetPersonTags;
use App\Modules\Crm\Application\UnknownTags;
use App\Modules\Crm\Application\UpdateContactMethod;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Shared\Domain\Actor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Crm;
use Tests\Support\Identity;
use Tests\Support\Race;

/*
 * CRM's write integrity under REAL concurrency, across two PHP processes and two database connections (method:
 * Tests\Support\Race). The first process does its work inside an open transaction and stops before committing; a second
 * process then runs a competing operation, and the test checks that it waited (or, where there is nothing to wait for,
 * that nothing was lost) and that the committed state is right. No sleeps of its own: only the harness's fixed wait.
 *
 * Runs on MariaDB and PostgreSQL: `./flow test backend` and `./flow test backend --pgsql`.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
});

afterEach(function () {
    Race::clean();
});

/** @return array<string, string> the worker's identification of the acting Guardian */
function crmActorArgs(Actor $by): array
{
    return ['actor_account' => $by->accountId->value, 'actor_person' => $by->personId->value];
}

it('serializes two additions of the same contact method: the second waits, then is refused, and one row remains', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');

    // The first holds the Person's write lock and has not committed when the second starts.
    $race = Race::against(
        function (Closure $pause) use ($by, $ada) {
            DB::transaction(function () use ($by, $ada, $pause) {
                app(AddContactMethod::class)($by, $ada->id, new NewContactMethod(ContactMethodKind::Email, 'x@example.org'));
                $pause();
            });
        },
        null, 'crm_add_method', [...crmActorArgs($by), 'person' => $ada->id->value, 'kind' => 'email', 'value' => 'X@Example.org', 'primary' => '0'],
    );

    expect($race['blocked'])->toBeTrue('the second addition did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(DuplicateContactMethod::class)
        ->and(DB::table('contact_methods')->where('person_id', $ada->id->value)->count())->toBe(1);
});

it('serializes two changes of the primary: both succeed, one after the other, and exactly one primary remains', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $first = Crm::email($by, $ada->id, 'a1@example.org');   // the primary
    $second = Crm::email($by, $ada->id, 'a2@example.org');

    $race = Race::against(
        function (Closure $pause) use ($by, $ada, $second) {
            DB::transaction(function () use ($by, $ada, $second, $pause) {
                app(UpdateContactMethod::class)($by, $ada->id, $second->id, ['is_primary' => true]);
                $pause();
            });
        },
        null, 'crm_set_primary', [...crmActorArgs($by), 'person' => $ada->id->value, 'method' => $first->id->value],
    );

    $primaries = DB::table('contact_methods')->where('person_id', $ada->id->value)->whereNotNull('primary_kind')->pluck('id')->all();
    expect($race['blocked'])->toBeTrue('the second change did not wait for the first to commit')
        ->and($race['exit'])->toBe(0)
        ->and($primaries)->toBe([$first->id->value]); // the last one committed wins; never two, never none
});

it('lets two creations of one tag name through only once: the second waits, then is refused', function () {
    $by = Crm::manager();

    $race = Race::against(
        function (Closure $pause) use ($by) {
            DB::transaction(function () use ($by, $pause) {
                app(CreateTag::class)($by, 'Lead');
                $pause();
            });
        },
        null, 'crm_create_tag', [...crmActorArgs($by), 'name' => 'LEAD'],
    );

    expect($race['blocked'])->toBeTrue('the second creation did not wait on the unique index')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(DuplicateTag::class)
        ->and(DB::table('contact_tags')->pluck('name_canonical')->all())->toBe(['lead']);
});

it('serializes two assignments of the same tag to a Person: the second waits and finds nothing to do', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $tag = Crm::tag($by, 'Partner');

    $race = Race::against(
        function (Closure $pause) use ($by, $ada, $tag) {
            DB::transaction(function () use ($by, $ada, $tag, $pause) {
                app(SetPersonTags::class)($by, $ada->id, [$tag]);
                $pause();
            });
        },
        null, 'crm_set_tags', [...crmActorArgs($by), 'person' => $ada->id->value, 'tag' => $tag->value],
    );

    expect($race['blocked'])->toBeTrue('the second assignment did not wait for the first to commit')
        ->and($race['exit'])->toBe(0) // not a unique-key error: it saw the committed assignment and had nothing to add
        ->and(DB::table('contact_tag_assignments')->where('person_id', $ada->id->value)->count())->toBe(1);
});

it('never loses an assignment to a tag deleted at the same moment: the assignment is refused and nothing dangles', function () {
    $by = Crm::manager();
    $ada = Identity::savedPerson('Ada');
    $tag = Crm::tag($by, 'Doomed');

    // The delete has checked the tag is unused and removed it, and has not committed when the assignment arrives.
    $race = Race::against(
        function (Closure $pause) use ($by, $tag) {
            DB::transaction(function () use ($by, $tag, $pause) {
                app(DeleteTag::class)($by, $tag);
                $pause();
            });
        },
        null, 'crm_set_tags', [...crmActorArgs($by), 'person' => $ada->id->value, 'tag' => $tag->value],
    );

    expect($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(UnknownTags::class)
        ->and(DB::table('contact_tags')->count())->toBe(0)
        ->and(DB::table('contact_tag_assignments')->count())->toBe(0);
});

it('lets two registrations of the same email both succeed as distinct Persons: duplicate advice is advice, and neither is lost', function () {
    $by = Crm::manager();

    // Nothing is shared between two NEW People, so there is nothing to wait for; the advisory check cannot see the other's
    // uncommitted Person, which is exactly why a duplicate is advice and never a constraint.
    $race = Race::against(
        function (Closure $pause) use ($by) {
            DB::transaction(function () use ($by, $pause) {
                app(RegisterContact::class)($by, 'Twin One', null, null, [new NewContactMethod(ContactMethodKind::Email, 'twin@example.org')], false);
                $pause();
            });
        },
        null, 'crm_register', [...crmActorArgs($by), 'name' => 'Twin Two', 'email' => 'twin@example.org'],
    );

    expect($race['blocked'])->toBeFalse()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('people')->whereIn('display_name', ['Twin One', 'Twin Two'])->count())->toBe(2)
        ->and(DB::table('contact_methods')->where('search_value', 'twin@example.org')->count())->toBe(2);
});
