<?php

declare(strict_types=1);

use App\Modules\Crm\Application\DeleteTag;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\PossibleDuplicate;
use App\Modules\Crm\Application\RegisterContact;
use App\Modules\Crm\Application\RenameTag;
use App\Modules\Crm\Application\SetPersonTags;
use App\Modules\Crm\Application\TagInUse;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactTagId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Database\Seeders\CrmDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Api;
use Tests\Support\Crm;
use Tests\Support\Identity;

/*
 * The CRM demo dataset (G1 WP6): what it makes, that it can be run again, that it can only run where a demo belongs, and that
 * the advice case it exists for really is advice. Runs on MariaDB and PostgreSQL: it goes through the CRM's own use cases.
 */

/** Runs the demo seeder as though the application were in the named environment (the default is `testing`). */
function seedDemo(?string $environment = null): void
{
    $original = app()->environment();
    if ($environment !== null) {
        app()->instance('env', $environment);
        config()->set('app.env', $environment);
    }

    try {
        (new CrmDemoSeeder)->setContainer(app())->run();
    } finally {
        app()->instance('env', $original);
        config()->set('app.env', $original);
    }
}

/**
 * Two operators, as the seeder finds them (the first two by email that may manage People).
 *
 * @return array{Actor, Actor}
 */
function demoOperators(): array
{
    return [Crm::manager('demo.a@example.org'), Crm::manager('demo.b@example.org')];
}

function personNamed(string $name): string
{
    $id = DB::table('people')->where('display_name', $name)->value('id');
    assert(is_string($id));

    return $id;
}

it('makes a small, believable CRM: People, the demo tags and their notes, written as real operators', function () {
    [$a, $b] = demoOperators();
    seedDemo();

    expect(DB::table('contact_tags')->orderBy('name')->pluck('name')->all())->toEqualCanonicalizing(CrmDemoSeeder::TAGS)
        ->and(DB::table('people')->whereNotIn('id', [$a->personId->value, $b->personId->value])->count())->toBe(8)
        // Everyone is a Person with no Account of their own: the seeder creates no Account, role or Membership.
        ->and(DB::table('accounts')->count())->toBe(2)
        ->and(DB::table('role_assignments')->count())->toBe(2)
        ->and(DB::table('membership_grants')->count())->toBe(0);

    $longest = DB::table('contact_interactions')->where('person_id', personNamed(CrmDemoSeeder::LONG_NOTE_PERSON))->orderBy('occurred_at')->value('body');
    expect(DB::table('contact_interactions')->where('person_id', personNamed(CrmDemoSeeder::PAGING_PERSON))->count())->toBeGreaterThan(10) // more than one page
        ->and(strlen(Api::string($longest)))->toBeGreaterThan(600); // long enough to wrap at a narrow width
});

it('covers every shape a Guardian meets: sparse, email only, phone only, both, tagged and untagged, every kind of note', function () {
    demoOperators();
    seedDemo();

    $methods = fn (string $name): array => DB::table('contact_methods')->where('person_id', personNamed($name))->orderBy('kind')->pluck('kind')->all();
    $tags = fn (string $name): int => DB::table('contact_tag_assignments')->where('person_id', personNamed($name))->count();

    expect($methods('Ruth Abernathy'))->toBe([])                                  // sparse: a name and nothing else
        ->and($tags('Ruth Abernathy'))->toBe(0)
        ->and(DB::table('contact_interactions')->where('person_id', personNamed('Ruth Abernathy'))->count())->toBe(0)
        ->and($methods('Tomasz Vance'))->toBe(['email'])                          // email only
        ->and($methods('Priya Raman'))->toBe(['phone'])                           // phone only
        ->and($methods(CrmDemoSeeder::PAGING_PERSON))->toBe(['email', 'phone'])   // both
        ->and($methods('Kenji Watanabe'))->toBe(['email', 'email'])               // two of a kind
        ->and($tags(CrmDemoSeeder::PAGING_PERSON))->toBe(2)                       // several tags
        ->and($tags('Sofia Lindqvist'))->toBe(0)                                  // none
        ->and(DB::table('contact_interactions')->distinct()->orderBy('kind')->pluck('kind')->all())->toBe(['call', 'email', 'meeting', 'note'])
        ->and(DB::table('contact_profiles')->where('person_id', personNamed('Tomasz Vance'))->value('affiliation'))->toBe('Vance & Daughter');

    // One primary per kind, as the server rules, even where a Person has two emails.
    expect(DB::table('contact_methods')->where('person_id', personNamed('Kenji Watanabe'))->whereNotNull('primary_kind')->count())->toBe(1);
});

it('has more than one author in the history, and a note whose last editor is not its author', function () {
    [$a, $b] = demoOperators();
    seedDemo();

    $authors = DB::table('contact_interactions')->distinct()->pluck('author_person_id')->all();
    $edited = DB::table('contact_interactions')->whereNotNull('updated_by_person_id')->first();

    expect($authors)->toEqualCanonicalizing([$a->personId->value, $b->personId->value])
        ->and($edited)->not->toBeNull()
        ->and($edited->updated_by_person_id ?? null)->toBe($b->personId->value)
        ->and($edited->author_person_id ?? null)->toBe($a->personId->value);
});

it('uses the one operator for every author when there is only one, and does not fail', function () {
    $only = Crm::manager('only.operator@example.org');
    seedDemo();

    expect(DB::table('contact_interactions')->distinct()->pluck('author_person_id')->all())->toBe([$only->personId->value]);
});

it('can be run again without adding anything or touching what a Guardian has since done', function () {
    [$a] = demoOperators();
    seedDemo();
    $counts = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), ['people', 'contact_methods', 'contact_tags', 'contact_tag_assignments', 'contact_interactions', 'contact_profiles']);

    // A Guardian changes things in the meantime.
    DB::table('contact_profiles')->where('person_id', personNamed('Tomasz Vance'))->update(['affiliation' => 'Changed since']);
    app(RegisterContact::class)($a, 'A Person Added By Hand', null, null, [], true);
    $before = $counts();

    seedDemo();

    expect($counts())->toBe($before)
        ->and(DB::table('contact_profiles')->where('person_id', personNamed('Tomasz Vance'))->value('affiliation'))->toBe('Changed since');
});

it('points notes whose operators were replaced at the current operators, and touches nothing else', function () {
    [$a, $b] = demoOperators();
    seedDemo();
    $paging = personNamed(CrmDemoSeeder::PAGING_PERSON);
    $rows = fn () => DB::table('contact_interactions')->where('person_id', $paging)->orderBy('occurred_at')->orderBy('id')->get(['id', 'author_person_id', 'updated_by_person_id', 'body']);
    $before = $rows();

    // The operators are recreated, as the browser suite recreates its fixtures: their Persons are gone, new ones stand in.
    $oldA = $a->personId->value;
    DB::table('contact_interactions')->update(['author_person_id' => PersonId::generate()->value]);
    DB::table('contact_interactions')->whereNotNull('updated_by_person_id')->update(['updated_by_person_id' => PersonId::generate()->value]);
    $interactions = DB::table('contact_interactions')->count();
    $kept = DB::table('contact_interactions')->where('person_id', personNamed('Tomasz Vance'))->orderBy('occurred_at')->first();
    DB::table('contact_interactions')->where('id', $kept?->id)->update(['author_person_id' => $b->personId->value]); // this one still resolves

    seedDemo();

    expect(DB::table('contact_interactions')->count())->toBe($interactions) // nothing added
        ->and($rows()->map(fn ($r) => $r->author_person_id)->all())->toBe($before->map(fn ($r) => $r->author_person_id)->all()) // as first chosen
        ->and($rows()->map(fn ($r) => $r->updated_by_person_id)->all())->toBe($before->map(fn ($r) => $r->updated_by_person_id)->all())
        ->and($rows()->map(fn ($r) => $r->body)->all())->toBe($before->map(fn ($r) => $r->body)->all())
        ->and(DB::table('contact_interactions')->where('id', $kept?->id)->value('author_person_id'))->toBe($b->personId->value)
        ->and($oldA)->toBe($a->personId->value);
});

it('leaves a Person who exists under a demo name alone, and writes nothing about them', function () {
    [$a] = demoOperators();
    app(RegisterContact::class)($a, 'Marguerite Hale', null, null, [], true); // someone already here with that name
    seedDemo();

    expect(DB::table('people')->where('display_name', 'Marguerite Hale')->count())->toBe(1)
        ->and(DB::table('contact_interactions')->where('person_id', personNamed('Marguerite Hale'))->count())->toBe(0)
        ->and(DB::table('people')->where('display_name', 'Tomasz Vance')->count())->toBe(1); // the rest still arrive
});

it('refuses to run outside local and testing, and writes nothing', function () {
    demoOperators();

    foreach (['production', 'staging'] as $environment) {
        expect(fn () => seedDemo($environment))->toThrow(RuntimeException::class, 'only be seeded in a local or testing environment');
    }
    expect(DB::table('contact_tags')->count())->toBe(0)
        ->and(DB::table('contact_interactions')->count())->toBe(0);
});

it('needs an operator to write as, and says so, rather than inventing one', function () {
    expect(fn () => seedDemo())->toThrow(RuntimeException::class, 'no active Account that may manage People')
        ->and(DB::table('people')->count())->toBe(0);
});

it('ignores an Account that may not manage People, however early its email sorts', function () {
    Identity::savedActiveAccount('a.plain@example.org'); // sorts first, holds nothing
    [$operator] = [Crm::manager('z.operator@example.org')];
    seedDemo();

    expect(DB::table('contact_interactions')->distinct()->pluck('author_person_id')->all())->toBe([$operator->personId->value]);
});

it('is not part of the default seeder, and nothing in the application refers to it', function () {
    $root = dirname(__DIR__, 4);
    expect((string) file_get_contents("{$root}/database/seeders/DatabaseSeeder.php"))->not->toContain('CrmDemo');

    $offenders = [];
    foreach (['app', 'bootstrap', 'config', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'CrmDemoSeeder')) {
                $offenders[] = str_replace("{$root}/", '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([])
        ->and(class_exists(DatabaseSeeder::class))->toBeTrue()
        ->and(str_contains((string) file_get_contents("{$root}/database/seeders/CrmDemoSeeder.php"), 'CrmDemoSeeder'))->toBeTrue(); // positive control
});

it('records no Account login as a CRM contact method, and makes no Account for anyone', function () {
    [$a, $b] = demoOperators();
    seedDemo();

    $logins = DB::table('accounts')->pluck('email')->map(fn ($e) => strtolower(Api::string($e)))->all();
    $recorded = DB::table('contact_methods')->pluck('value')->map(fn ($v) => strtolower(Api::string($v)))->all();

    expect(array_intersect($logins, $recorded))->toBe([])
        ->and(DB::table('accounts')->count())->toBe(2);
});

describe('the duplicate-advice case', function () {
    it('is advice by a shared CRM email: the candidate is named, nothing is created, nothing merged', function () {
        [$a] = demoOperators();
        seedDemo();
        $hannah = personNamed(CrmDemoSeeder::DUPLICATE_ADVICE_PERSON);
        $people = DB::table('people')->count();

        try {
            app(RegisterContact::class)($a, 'H. Moreau', null, null, [new NewContactMethod(ContactMethodKind::Email, strtoupper(CrmDemoSeeder::DUPLICATE_ADVICE_EMAIL))], false);
            $advice = null;
        } catch (PossibleDuplicate $e) {
            $advice = $e->candidates;
        }

        $candidate = $advice[0] ?? null;
        expect($advice)->toHaveCount(1)
            ->and($candidate?->person->id->value)->toBe($hannah)
            ->and($candidate->matchedOn ?? null)->toBe(['email'])
            ->and(DB::table('people')->count())->toBe($people);
    });

    it('is advice by the exact name too', function () {
        [$a] = demoOperators();
        seedDemo();

        $matched = null;
        try {
            app(RegisterContact::class)($a, 'hannah MOREAU', null, null, [], false);
        } catch (PossibleDuplicate $e) {
            $matched = $e->candidates[0]->matchedOn ?? null;
        }

        expect($matched)->toBe(['display_name']);
    });

    it('registers a distinct Person on an explicit confirmation, and leaves the demo Person exactly as she was', function () {
        [$a] = demoOperators();
        seedDemo();
        $hannah = personNamed(CrmDemoSeeder::DUPLICATE_ADVICE_PERSON);
        $methodsBefore = DB::table('contact_methods')->where('person_id', $hannah)->orderBy('id')->pluck('value')->all();

        $created = app(RegisterContact::class)($a, 'H. Moreau', null, null, [new NewContactMethod(ContactMethodKind::Email, CrmDemoSeeder::DUPLICATE_ADVICE_EMAIL)], true);

        expect($created->person->id->value)->not->toBe($hannah)
            ->and(DB::table('contact_methods')->where('person_id', $hannah)->orderBy('id')->pluck('value')->all())->toBe($methodsBefore)
            ->and(DB::table('contact_methods')->where('search_value', CrmDemoSeeder::DUPLICATE_ADVICE_EMAIL)->count())->toBe(2); // shared, not unique
    });
});

describe('the demo tags are labels a Guardian manages', function () {
    it('can be renamed, and deleted once nobody holds them, like any tag', function () {
        [$a] = demoOperators();
        seedDemo();
        $vendor = ContactTagId::fromString(Api::string(DB::table('contact_tags')->where('name', 'Vendor')->value('id')));
        $tomasz = PersonId::fromString(personNamed('Tomasz Vance'));

        app(RenameTag::class)($a, $vendor, 'Supplier');
        expect(DB::table('contact_tags')->where('id', $vendor->value)->value('name'))->toBe('Supplier');

        expect(fn () => app(DeleteTag::class)($a, $vendor))->toThrow(TagInUse::class); // held by someone: refused, kept
        app(SetPersonTags::class)($a, $tomasz, []);
        app(DeleteTag::class)($a, $vendor);

        expect(DB::table('contact_tags')->where('id', $vendor->value)->exists())->toBeFalse();
    });

    it('mean nothing to the platform: no role, capability or Membership is derived from a tag name', function () {
        demoOperators();
        $rolesBefore = DB::table('role_assignments')->count();
        seedDemo();

        // A "Volunteer Interest" and a "Partner" exist, and still nobody has gained any access.
        expect(DB::table('role_assignments')->count())->toBe($rolesBefore)
            ->and(DB::table('membership_grants')->count())->toBe(0);
    });
});
