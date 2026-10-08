<?php

declare(strict_types=1);

use App\Modules\Resources\Application\BrowseResourceLibrary;
use App\Modules\Resources\Application\GetResourcePack;
use App\Modules\Resources\Application\PruneOrphanedAssets;
use App\Modules\Resources\Application\ResourcePackNotFound;
use App\Modules\Resources\Domain\FileStoreFailure;
use App\Modules\Resources\Domain\PackId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ResourcesDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\FaultyFileStore;
use Tests\Support\Identity;
use Tests\Support\ResourceFiles;
use Tests\Support\Resources;
use Tests\Support\SourceScan;

/*
 * The Resources demo dataset (G5 WP6): what it makes, that it can be run again without adding or changing anything, that it can only run
 * where a demo belongs, and that it is written through Resources' own use cases, so what it shows is what a Guardian could have authored
 * and what the Guardian library delivers from it is what the projection says. Runs on MariaDB and PostgreSQL.
 */

beforeEach(function () {
    ResourceFiles::fake();
});

/** Runs the demo seeder as though the application were in the named environment (the default is `testing`). */
function seedResourcesDemo(?string $environment = null): void
{
    $original = app()->environment();
    if ($environment !== null) {
        app()->instance('env', $environment);
        config()->set('app.env', $environment);
    }

    try {
        (new ResourcesDemoSeeder)->setContainer(app())->run();
    } finally {
        app()->instance('env', $original);
        config()->set('app.env', $original);
    }
}

/** @return array<string, int> */
function demoCounts(): array
{
    $counts = [];
    foreach (['resource_categories', 'resource_packs', 'resource_cards', 'resource_assets', 'resource_pack_audiences', 'resource_card_audiences'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    $counts['stored files'] = count(ResourceFiles::stored());

    return $counts;
}

/**
 * Every row of the demo's tables, in a fixed order, as plain arrays: two snapshots are equal only if nothing at all changed.
 *
 * @return array<string, list<array<mixed>>>
 */
function demoSnapshot(): array
{
    $rows = [];
    foreach (['resource_categories', 'resource_packs', 'resource_cards', 'resource_assets'] as $table) {
        $rows[$table] = array_values(DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }
    $rows['resource_pack_audiences'] = array_values(DB::table('resource_pack_audiences')->orderBy('pack_id')->orderBy('audience')->get()->map(fn ($row) => (array) $row)->all());
    $rows['resource_card_audiences'] = array_values(DB::table('resource_card_audiences')->orderBy('card_id')->orderBy('audience')->get()->map(fn ($row) => (array) $row)->all());

    return $rows;
}

function demoPackRow(string $title): stdClass
{
    $row = DB::table('resource_packs')->where('title', $title)->first();
    assert($row instanceof stdClass);

    return $row;
}

/** @return list<stdClass> a Pack's Cards in position order, straight from the table */
function demoCardRows(string $packTitle): array
{
    return array_values(DB::table('resource_cards')->where('pack_id', demoPackRow($packTitle)->id)->orderBy('position')->get()->all());
}

/** @return list<string> */
function demoAudiencesOfPack(string $title): array
{
    return Resources::strings(DB::table('resource_pack_audiences')->where('pack_id', demoPackRow($title)->id)->orderBy('audience')->pluck('audience'));
}

function demoGuardian(): Actor
{
    return Resources::editor('demo.guardian@example.org', 'Demo Guardian');
}

/** @return list<string> */
function demoLibraryTitles(Actor $as, ?string $search = null): array
{
    $titles = [];
    foreach (app(BrowseResourceLibrary::class)($as, null, $search) as $entry) {
        foreach ($entry->packs as $listed) {
            $titles[] = $listed->pack->title;
        }
    }

    return $titles;
}

it('makes four Categories, eight Packs, sixteen Cards and three files, through the use cases, and no Person, Account or role of its own', function () {
    $operator = demoGuardian();
    $accounts = DB::table('accounts')->count();
    $people = DB::table('people')->count();
    seedResourcesDemo();

    expect(DB::table('resource_categories')->orderBy('position')->pluck('name')->all())->toBe(ResourcesDemoSeeder::categories())
        ->and(DB::table('resource_packs')->count())->toBe(8)
        ->and(DB::table('resource_cards')->count())->toBe(16)
        ->and(DB::table('resource_assets')->count())->toBe(3)
        ->and(DB::table('accounts')->count())->toBe($accounts)
        ->and(DB::table('people')->count())->toBe($people)
        ->and(DB::table('resource_packs')->pluck('created_by_person_id')->unique()->all())->toBe([$operator->personId->value]);
});

it('gives each Pack the Category, audiences, Series and publication state it exists to show', function () {
    demoGuardian();
    seedResourcesDemo();

    $state = fn (string $title): array => [
        Resources::str(DB::table('resource_categories')->where('id', demoPackRow($title)->category_id)->value('name')),
        demoAudiencesOfPack($title),
        (bool) demoPackRow($title)->is_series,
        demoPackRow($title)->state,
    ];

    expect($state(ResourcesDemoSeeder::NARROWED))->toBe([ResourcesDemoSeeder::GETTING_STARTED, ['guardian', 'member'], true, 'published'])
        ->and($state(ResourcesDemoSeeder::SINGLE))->toBe([ResourcesDemoSeeder::RUNNING, ['guardian'], false, 'published'])
        ->and($state(ResourcesDemoSeeder::OPERATIONS))->toBe([ResourcesDemoSeeder::RUNNING, ['guardian'], false, 'published'])
        ->and($state(ResourcesDemoSeeder::LINK))->toBe([ResourcesDemoSeeder::RUNNING, ['guardian'], false, 'published'])
        ->and($state(ResourcesDemoSeeder::IMAGE))->toBe([ResourcesDemoSeeder::RUNNING, ['guardian'], false, 'published'])
        ->and($state(ResourcesDemoSeeder::DRAFT))->toBe([ResourcesDemoSeeder::RUNNING, ['guardian'], false, 'draft'])
        ->and($state(ResourcesDemoSeeder::SERIES))->toBe([ResourcesDemoSeeder::SAFETY, ['guardian'], true, 'published'])
        ->and($state(ResourcesDemoSeeder::MEMBERS_ONLY))->toBe([ResourcesDemoSeeder::MEMBER_CIRCLE, ['member'], false, 'published']);
});

it('gives each Card the Type, state and audience it exists to show', function () {
    demoGuardian();
    seedResourcesDemo();
    $summary = fn (string $pack): array => array_map(
        static fn (stdClass $c): array => [$c->title, $c->type, $c->state, $c->audience_mode],
        demoCardRows($pack),
    );

    expect($summary(ResourcesDemoSeeder::SINGLE))->toBe([[ResourcesDemoSeeder::SINGLE, 'basic', 'published', 'inherit']])
        // A Series with a Member-only Card between two Guardian Cards, and a last one after it.
        ->and($summary(ResourcesDemoSeeder::NARROWED))->toBe([
            ['Welcome to the team', 'basic', 'published', 'inherit'],
            [ResourcesDemoSeeder::HIDDEN_CARD, 'basic', 'published', 'narrowed'],
            ['Your first shift', 'basic', 'published', 'inherit'],
            ['Handover and support', 'basic', 'published', 'inherit'],
        ])
        // Three to choose between, and a Draft that nobody but management sees.
        ->and($summary(ResourcesDemoSeeder::OPERATIONS))->toBe([
            ['Keys, alarm and access', 'basic', 'published', 'inherit'],
            ['Cleaning and supplies', 'basic', 'published', 'inherit'],
            ['Shift rota template', 'file', 'published', 'inherit'],
            ['Winter heating guide', 'basic', 'draft', 'inherit'],
        ])
        ->and($summary(ResourcesDemoSeeder::LINK))->toBe([['Open the room booking calendar', 'external_link', 'published', 'inherit']])
        ->and($summary(ResourcesDemoSeeder::IMAGE))->toBe([['Floor plan of the sanctuary', 'file', 'published', 'inherit']])
        ->and($summary(ResourcesDemoSeeder::SERIES))->toBe([
            ['Why fire safety matters', 'basic', 'published', 'inherit'],
            ['Know your exits, and where to meet if you have to leave the building', 'basic', 'published', 'inherit'],
            ['Evacuation plan', 'file', 'published', 'inherit'],
        ])
        ->and($summary(ResourcesDemoSeeder::DRAFT))->toBe([['Winter dates', 'basic', 'draft', 'inherit']])
        ->and(DB::table('resource_card_audiences')->where('card_id', demoCardRows(ResourcesDemoSeeder::NARROWED)[1]->id)->pluck('audience')->all())->toBe(['member'])
        ->and(Resources::str(demoCardRows(ResourcesDemoSeeder::LINK)[0]->external_uri))->toBe(ResourcesDemoSeeder::LINK_ADDRESS);
});

it('stores each file through the Resources store, as a real file of its kind, and no other', function () {
    demoGuardian();
    seedResourcesDemo();

    $assets = DB::table('resource_assets')->orderBy('original_filename')->get();
    expect($assets->pluck('original_filename')->all())->toBe(['evacuation-plan.pdf', 'sanctuary-floor-plan.png', 'two-week-shift-rota-template-for-sanctuary-guardians.csv'])
        ->and($assets->pluck('media_type')->all())->toBe(['application/pdf', 'image/png', 'text/csv'])
        ->and(ResourceFiles::stored())->toHaveCount(3);

    foreach ($assets as $asset) {
        $bytes = ResourceFiles::storedBytes(Resources::str($asset->storage_key));
        $committed = (string) file_get_contents(dirname(__DIR__, 4).'/database/seeders/resources-demo/'.Resources::str($asset->original_filename));
        expect($bytes)->toBe($committed)
            ->and(Resources::int($asset->byte_size))->toBe(strlen($committed))
            ->and(Resources::str($asset->sha256))->toBe(hash('sha256', $committed));
    }
});

it('keeps the committed files tiny', function () {
    $dir = dirname(__DIR__, 4).'/database/seeders/resources-demo';
    $files = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));

    expect($files)->toBe(['evacuation-plan.pdf', 'sanctuary-floor-plan.png', 'two-week-shift-rota-template-for-sanctuary-guardians.csv']);
    foreach ($files as $file) {
        expect(filesize("{$dir}/{$file}"))->toBeLessThan(4096);
    }
});

it('covers the rich content the renderer must draw: headings, lists, a link, a table, a quotation and code', function () {
    demoGuardian();
    seedResourcesDemo();
    $stored = implode("\n", Resources::strings(DB::table('resource_cards')->pluck('content_document')));

    foreach (['"type":"heading"', '"type":"bulletList"', '"type":"orderedList"', '"type":"table"', '"type":"tableHeader"', '"type":"blockquote"', '"type":"codeBlock"', '"type":"link"'] as $needle) {
        expect($stored)->toContain($needle);
    }
});

it('stamps fixed past times, so the data does not depend on the day it is made, and puts the clock back as it was', function () {
    demoGuardian();
    Carbon::setTestNow(Carbon::parse('2031-01-01 00:00:00 UTC'));
    try {
        seedResourcesDemo();
        $after = Carbon::now()->toDateTimeString();
    } finally {
        Carbon::setTestNow();
    }

    expect($after)->toBe('2031-01-01 00:00:00')                                   // not left frozen in the past
        ->and(demoPackRow(ResourcesDemoSeeder::NARROWED)->created_at)->toStartWith('2026-09-08 09:00:00')
        ->and(DB::table('resource_categories')->min('created_at'))->toStartWith('2026-09-08 08:00:00')
        ->and(DB::table('resource_packs')->max('updated_at'))->toBeLessThan('2026-10-01')
        ->and(DB::table('resource_cards')->max('updated_at'))->toBeLessThan('2026-10-01')
        ->and(DB::table('resource_assets')->max('created_at'))->toBeLessThan('2026-10-01');
});

it('is run again without adding or changing anything: no Category, Pack, Card, asset row or file, and no timestamp', function () {
    demoGuardian();
    seedResourcesDemo();
    $counts = demoCounts();
    $snapshot = demoSnapshot();
    $stored = ResourceFiles::stored();

    Carbon::setTestNow(Carbon::parse('2031-06-01 12:00:00 UTC')); // a different day: a rerun must not stamp it
    try {
        seedResourcesDemo();
        seedResourcesDemo();
    } finally {
        Carbon::setTestNow();
    }

    expect(demoCounts())->toBe($counts)
        ->and(demoSnapshot())->toEqual($snapshot)
        ->and(ResourceFiles::stored())->toBe($stored)
        ->and(DB::table('security_events')->where('type', 'like', 'resource.%')->count())->toBe(0);
});

it('leaves no orphaned file and no file-less asset: the store and the asset rows agree', function () {
    demoGuardian();
    seedResourcesDemo();
    seedResourcesDemo();

    $report = app(PruneOrphanedAssets::class)(new DateTimeImmutable('+10 days'), true); // far past the grace, so any orphan would be listed
    expect($report->removed)->toBe([])
        ->and($report->missing)->toBe([])
        ->and($report->kept)->toBe(3)
        ->and(ResourceFiles::stored())->toHaveCount(3);
});

it('leaves a Pack that exists under a demo title alone, and makes only the rest', function () {
    $by = demoGuardian();
    $mine = Resources::pack($by, ResourcesDemoSeeder::SINGLE, summary: 'Someone already wrote this one');
    seedResourcesDemo();

    expect(DB::table('resource_packs')->where('title', ResourcesDemoSeeder::SINGLE)->count())->toBe(1)
        ->and(DB::table('resource_cards')->where('pack_id', $mine->pack->id->value)->count())->toBe(0) // nothing written in it
        ->and(demoPackRow(ResourcesDemoSeeder::SINGLE)->summary)->toBe('Someone already wrote this one')
        ->and(DB::table('resource_packs')->count())->toBe(8); // the other seven arrived
});

it('does not make a Category twice when a Guardian already has one of that name', function () {
    $by = demoGuardian();
    Resources::category($by, ResourcesDemoSeeder::RUNNING);
    seedResourcesDemo();

    expect(DB::table('resource_categories')->where('name', ResourcesDemoSeeder::RUNNING)->count())->toBe(1)
        ->and(DB::table('resource_categories')->count())->toBe(4);
});

it('makes a Pack whole or not at all: a failure leaves no half-made Pack to be skipped on the next run', function () {
    demoGuardian();
    $store = FaultyFileStore::install();
    $store->failPut = true; // the third Pack's File Card cannot be stored: after its Pack and two Cards were written

    expect(fn () => seedResourcesDemo())->toThrow(FileStoreFailure::class);
    $made = Resources::strings(DB::table('resource_packs')->pluck('title'));
    expect($made)->toBe([ResourcesDemoSeeder::NARROWED, ResourcesDemoSeeder::SINGLE]) // the two before it, whole; the one that failed, not at all
        ->and(DB::table('resource_cards')->count())->toBe(5)
        ->and(DB::table('resource_assets')->count())->toBe(0)
        ->and(ResourceFiles::stored())->toBe([]);

    $store->failPut = false;
    seedResourcesDemo();
    expect(DB::table('resource_packs')->count())->toBe(8)
        ->and(DB::table('resource_cards')->count())->toBe(16);
});

it('shows a Guardian only the Cards they may read: a Series with a Member-only Card is A, C and D as 1, 2 and 3 of 3', function () {
    $guardian = demoGuardian();
    seedResourcesDemo();

    $delivered = app(GetResourcePack::class)($guardian, PackId::fromString(Resources::str(demoPackRow(ResourcesDemoSeeder::NARROWED)->id)));
    expect(array_map(fn ($c): string => $c->card->title, $delivered->cards))->toBe(['Welcome to the team', 'Your first shift', 'Handover and support'])
        ->and(array_map(fn ($c): int => $c->index, $delivered->cards))->toBe([1, 2, 3])
        ->and($delivered->pack->isSeries)->toBeTrue()
        ->and(json_encode($delivered->cards, JSON_THROW_ON_ERROR))->not->toContain(ResourcesDemoSeeder::HIDDEN_CARD)
        ->and(json_encode($delivered->cards, JSON_THROW_ON_ERROR))->not->toContain(ResourcesDemoSeeder::HIDDEN_WORD);

    // A Pack with a Draft Card in it delivers the three that are Published.
    $operations = app(GetResourcePack::class)($guardian, PackId::fromString(Resources::str(demoPackRow(ResourcesDemoSeeder::OPERATIONS)->id)));
    expect(array_map(fn ($c): string => $c->card->title, $operations->cards))->toBe(['Keys, alarm and access', 'Cleaning and supplies', 'Shift rota template']);
});

it('lists three Categories and six Packs to a Guardian, in order, with no Draft, no Member-only Pack and no Member-only Category', function () {
    $guardian = demoGuardian();
    seedResourcesDemo();

    $library = app(BrowseResourceLibrary::class)($guardian, null, null);
    expect(array_map(fn ($entry): string => $entry->category->name, $library))->toBe([ResourcesDemoSeeder::GETTING_STARTED, ResourcesDemoSeeder::RUNNING, ResourcesDemoSeeder::SAFETY])
        ->and(demoLibraryTitles($guardian))->toBe([
            ResourcesDemoSeeder::NARROWED, ResourcesDemoSeeder::SINGLE, ResourcesDemoSeeder::OPERATIONS, ResourcesDemoSeeder::LINK, ResourcesDemoSeeder::IMAGE, ResourcesDemoSeeder::SERIES,
        ]);

    // Counts are of what this viewer can see: three of the Onboarding Series' four, three of Operations' four.
    $counts = [];
    foreach ($library as $entry) {
        foreach ($entry->packs as $listed) {
            $counts[$listed->pack->title] = $listed->visibleCardCount;
        }
    }
    expect($counts[ResourcesDemoSeeder::NARROWED])->toBe(3)
        ->and($counts[ResourcesDemoSeeder::OPERATIONS])->toBe(3)
        ->and($counts[ResourcesDemoSeeder::SINGLE])->toBe(1);
});

it('answers a Draft Pack and a Member-only Pack exactly as a Pack that does not exist', function () {
    $guardian = demoGuardian();
    seedResourcesDemo();

    foreach ([ResourcesDemoSeeder::DRAFT, ResourcesDemoSeeder::MEMBERS_ONLY] as $title) {
        expect(fn () => app(GetResourcePack::class)($guardian, PackId::fromString(Resources::str(demoPackRow($title)->id))))->toThrow(ResourcePackNotFound::class);
    }
});

it('does not find hidden content by search: a Member-only Card, a Member-only Pack or a Draft by any of their words', function () {
    $guardian = demoGuardian();
    seedResourcesDemo();

    expect(demoLibraryTitles($guardian, ResourcesDemoSeeder::HIDDEN_WORD))->toBe([])
        ->and(demoLibraryTitles($guardian, 'etiquette'))->toBe([])                            // the Member-only Pack's Card
        ->and(demoLibraryTitles($guardian, ResourcesDemoSeeder::MEMBERS_ONLY))->toBe([])
        ->and(demoLibraryTitles($guardian, 'Winter'))->toBe([])                                // the Draft Pack, and the Draft Card of a Published one
        ->and(demoLibraryTitles($guardian, 'heating'))->toBe([])
        ->and(demoLibraryTitles($guardian, 'Onboarding'))->toBe([ResourcesDemoSeeder::NARROWED])
        ->and(demoLibraryTitles($guardian, 'Your first shift'))->toBe([ResourcesDemoSeeder::NARROWED]); // a visible Card's title finds its Pack
});

it('points rows whose operator was replaced at the current operator, and touches nothing else', function () {
    demoGuardian();
    seedResourcesDemo();
    $before = demoSnapshot();

    // The browser suite recreates its fixture Accounts, and their Persons, on every run: the demo's rows then name a Person that is gone.
    $gone = PersonId::generate()->value;
    DB::table('resource_categories')->update(['created_by_person_id' => $gone, 'updated_by_person_id' => $gone]);
    DB::table('resource_packs')->update(['created_by_person_id' => $gone]);
    DB::table('resource_cards')->where('title', 'Your first shift')->update(['updated_by_person_id' => $gone]);
    // And one row that a different, still existing, person has since changed.
    $other = Resources::editor('someone.else@example.org', 'Someone Else');
    DB::table('resource_packs')->where('title', ResourcesDemoSeeder::LINK)->update(['updated_by_person_id' => $other->personId->value]);
    $touched = demoSnapshot();

    // The operator is now the first by email who may manage: a different Account from before.
    $operator = Resources::editor('a.new.operator@example.org', 'A New Operator');
    seedResourcesDemo();

    $after = demoSnapshot();
    expect(DB::table('resource_categories')->pluck('created_by_person_id')->unique()->all())->toBe([$operator->personId->value])
        ->and(DB::table('resource_categories')->pluck('updated_by_person_id')->unique()->all())->toBe([$operator->personId->value])
        ->and(DB::table('resource_packs')->where('title', ResourcesDemoSeeder::LINK)->value('updated_by_person_id'))->toBe($other->personId->value) // still exists: untouched
        ->and(DB::table('resource_cards')->where('title', 'Your first shift')->value('updated_by_person_id'))->toBe($operator->personId->value)
        ->and(demoCounts()['resource_packs'])->toBe(8)
        // Nothing but provenance moved: the same rows, in the same states, with the same revisions and times.
        ->and(array_map(fn (array $row): array => array_diff_key($row, array_flip(['created_by_person_id', 'updated_by_person_id'])), $after['resource_packs']))
        ->toEqual(array_map(fn (array $row): array => array_diff_key($row, array_flip(['created_by_person_id', 'updated_by_person_id'])), $before['resource_packs']))
        ->and($after['resource_assets'])->toEqual($before['resource_assets']);
    expect($touched)->not->toEqual($after);
});

it('refuses to run outside local and testing, and writes nothing, not even a file', function () {
    demoGuardian();

    foreach (['production', 'staging'] as $environment) {
        expect(fn () => seedResourcesDemo($environment))->toThrow(RuntimeException::class, 'only be seeded in a local or testing environment');
    }
    expect(demoCounts())->toBe(array_fill_keys(array_keys(demoCounts()), 0));
});

it('runs in the local environment too', function () {
    demoGuardian();
    seedResourcesDemo('local');

    expect(DB::table('resource_packs')->count())->toBe(8);
});

it('needs an operator who may manage Resources to write as, and says so, rather than inventing one', function () {
    expect(fn () => seedResourcesDemo())->toThrow(RuntimeException::class, 'no active Account that may manage Resources')
        ->and(demoCounts()['resource_packs'])->toBe(0);

    Identity::savedActiveAccount('plain.account@example.org'); // active, but holds no role
    expect(fn () => seedResourcesDemo())->toThrow(RuntimeException::class, 'no active Account that may manage Resources');
});

it('ignores an Account that may not manage Resources, however early its email sorts', function () {
    Identity::savedActiveAccount('a.plain@example.org');
    $operator = Resources::editor('z.operator@example.org', 'Zed Operator');
    seedResourcesDemo();

    expect(DB::table('resource_packs')->pluck('created_by_person_id')->unique()->all())->toBe([$operator->personId->value]);
});

it('is not part of the default seeder, and nothing in the application refers to it', function () {
    $root = dirname(__DIR__, 4);
    expect((string) file_get_contents("{$root}/database/seeders/DatabaseSeeder.php"))->not->toContain('ResourcesDemo');

    $offenders = [];
    foreach (['app', 'bootstrap', 'config', 'routes'] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$dir}", FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'ResourcesDemoSeeder')) {
                $offenders[] = str_replace("{$root}/", '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([])
        ->and(class_exists(DatabaseSeeder::class))->toBeTrue()
        ->and(str_contains((string) file_get_contents("{$root}/database/seeders/ResourcesDemoSeeder.php"), 'ResourcesDemoSeeder'))->toBeTrue(); // positive control
});

/**
 * @return list<array{string, string, string}> [table, verb, the statement's text] for each direct database write in a source
 */
function directWrites(string $source): array
{
    preg_match_all('/DB::table\\(([^)]*)\\)(.*?);/s', $source, $statements, PREG_SET_ORDER);
    $writes = [];
    foreach ($statements as $statement) {
        if (preg_match('/->(insert|insertGetId|update|updateOrInsert|upsert|delete|truncate|increment|decrement)\(/', $statement[0], $verb) === 1) {
            $writes[] = [trim($statement[1], "'\" "), $verb[1], $statement[0]];
        }
    }

    return $writes;
}

it('writes directly only to repair three columns of the demo\'s own rows, and never stores a file, names the assets table or reaches another module', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/database/seeders/ResourcesDemoSeeder.php');
    $code = SourceScan::code($source);
    $writes = directWrites($code);

    // The scan sees a write (positive control) and ignores a read.
    expect(directWrites("<?php DB::table('x')->where('a', 1)->update(['b' => 2]);"))->toHaveCount(1)
        ->and(directWrites("<?php DB::table('x')->where('a', 1)->get();"))->toBe([])
        ->and($writes)->not->toBe([]);

    // Only the Resources tables that carry a creator and an editor; only an update; only those two columns.
    $tables = array_values(array_unique(array_map(static fn (array $w): string => $w[0], $writes)));
    sort($tables);
    expect($tables)->toBe(['$table'])                                                       // one statement, in a loop over the three below
        ->and(array_unique(array_map(static fn (array $w): string => $w[1], $writes)))->toBe(['update'])
        ->and($code)->toContain("['resource_categories' => \$ids['categories'], 'resource_packs' => \$ids['packs'], 'resource_cards' => \$ids['cards']]")
        ->and($code)->toContain("['created_by_person_id', 'updated_by_person_id']")
        ->and(preg_match_all("/'(resource_\\w+)'/", $code, $named))->toBe(3)
        ->and($named[1])->toBe(['resource_categories', 'resource_packs', 'resource_cards'])
        ->and($code)->not->toContain('resource_assets')                                    // nothing outside Resources may name it (ResourcesBoundariesTest)
        ->and(preg_match('/Storage::|->disk\(|Filesystem\b|file_put_contents|(?<![>\\w])copy\(|rename\(/', $code))->toBe(0) // a file reaches the store only through the intake
        ->and($code)->toContain('IncomingFile');
});
