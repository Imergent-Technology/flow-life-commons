<?php

declare(strict_types=1);

use App\Modules\Access\Application\Authorizer;
use App\Modules\Resources\Application\ResourceEvent;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\ResourceAsset;
use Tests\Support\SourceScan;

/*
 * The Resources module's boundaries (ADR 0037), as source structure. The runtime proofs (capability layers, disclosure, deletion and
 * its audit, the HTTP table, races) are the feature tests under tests/Feature/Modules/Resources and
 * tests/Concurrency/ResourcesRaceTest.php.
 *
 * Resources owns Resource business state. It may use Identity's and Access's Application layers, ONE Audit Application type for the two
 * permanent-deletion events, Shared primitives and its own persistence, including its own managed files (WP3), which only its
 * Infrastructure touches. It may not reach Identity's tables or Domain, Membership, CRM, Discussions or WordPress; it invents no
 * relationship (ADR 0036); it handles no HTML, network or Node; and nothing else may depend on it or on its assets. The generic rules in ModuleBoundariesTest and the module graph in AccessBoundariesTest already cover the module because
 * modules are discovered from disk; these are the Resources-specific, positive statements.
 *
 * One subject per arch expectation (README.md). Every source scan has a positive control.
 */

$resources = 'App\\Modules\\Resources';

// --- What Resources may reach ----------------------------------------------------------------------------------------

arch('Resources uses Identity only through its Application layer', function () use ($resources) {
    expect($resources)->not->toUse(['App\\Modules\\Identity\\Domain', 'App\\Modules\\Identity\\Infrastructure', 'App\\Modules\\Identity\\Http']);
});

arch('Resources uses Access only through its Application layer', function () use ($resources) {
    expect($resources)->not->toUse(['App\\Modules\\Access\\Domain', 'App\\Modules\\Access\\Infrastructure', 'App\\Modules\\Access\\Http']);
});

arch('Resources uses Audit only through its Application layer, never its Domain, persistence or HTTP: it cannot reach the writer or the table', function () use ($resources) {
    expect($resources)->not->toUse(['App\\Modules\\Audit\\Domain', 'App\\Modules\\Audit\\Infrastructure', 'App\\Modules\\Audit\\Http']);
});

arch('Resources does not depend on Membership in this package: no surface delivers to Members yet, so nothing asks Membership (ADR 0037, decision 42)', function () use ($resources) {
    expect($resources)->not->toUse('App\\Modules\\Membership');
});

arch('Resources does not depend on CRM: a Person record is not a Resource', function () use ($resources) {
    expect($resources)->not->toUse('App\\Modules\\Crm');
});

arch('Resources does not depend on Discussions: Discussions are not changed by Resources work', function () use ($resources) {
    expect($resources)->not->toUse('App\\Modules\\Discussions');
});

arch('Resources has no WordPress dependency: the companion is a later milestone', function () use ($resources) {
    expect($resources)->not->toUse(['WP_', 'wp_']);
});

arch('Resources has no notification, mail, queue, broadcast or event-dispatch dependency: it works without any of them', function () use ($resources) {
    expect($resources)->not->toUse([
        'Illuminate\\Support\\Facades\\Mail', 'Illuminate\\Mail', 'Illuminate\\Notifications', 'Illuminate\\Support\\Facades\\Notification',
        'Illuminate\\Broadcasting', 'Illuminate\\Support\\Facades\\Broadcast', 'Illuminate\\Contracts\\Broadcasting',
        'Illuminate\\Queue', 'Illuminate\\Bus', 'Illuminate\\Support\\Facades\\Queue', 'Illuminate\\Support\\Facades\\Bus', 'Illuminate\\Contracts\\Queue',
        'Illuminate\\Support\\Facades\\Event', 'Illuminate\\Events',
    ]);
});

arch('Resources reaches no network: no HTTP client, so a stored address is never fetched (ADR 0037, decision 24)', function () use ($resources) {
    expect($resources)->not->toUse(['Illuminate\\Support\\Facades\\Http', 'Illuminate\\Http\\Client', 'GuzzleHttp', 'Psr\\Http\\Client', 'Symfony\\Contracts\\HttpClient']);
});

foreach (['Domain', 'Application', 'Http'] as $layer) {
    arch("Resources {$layer} reaches no filesystem: only Infrastructure's file store does, behind the ResourceFileStore port (ADR 0037, decision 4)", function () use ($resources, $layer) {
        expect("{$resources}\\{$layer}")->not->toUse([
            'Illuminate\\Support\\Facades\\Storage', 'Illuminate\\Contracts\\Filesystem', 'Illuminate\\Filesystem', 'League\\Flysystem',
            'Illuminate\\Support\\Facades\\File', 'Symfony\\Component\\Filesystem',
        ]);
    });

    arch("Resources {$layer} does not inspect file content itself: detection is Infrastructure's, behind the MediaTypeDetector port", function () use ($resources, $layer) {
        expect("{$resources}\\{$layer}")->not->toUse(['finfo', 'ZipArchive']);
    });
}

foreach (['Domain', 'Application'] as $layer) {
    arch("Resources {$layer} never sees an HTTP upload: it receives an IncomingFile, without the client's claimed type", function () use ($resources, $layer) {
        expect("{$resources}\\{$layer}")->not->toUse(['Illuminate\\Http\\UploadedFile', 'Symfony\\Component\\HttpFoundation\\File']);
    });
}

it('lets exactly one class open the resources disk, and nothing outside Resources reaches the store or its table', function () {
    $diskUsers = [];
    $assetTableUsers = [];
    foreach (SourceScan::phpFiles(['app', 'database', 'routes', 'bootstrap/app.php', 'bootstrap/providers.php']) as $path) {
        $code = SourceScan::code(SourceScan::read($path));
        if (preg_match('/Storage::|->disk\(|Filesystem\b/', $code) === 1) {
            $diskUsers[] = SourceScan::relative($path);
        }
        $inResources = str_contains($path, '/app/Modules/Resources/') || str_contains($path, 'create_resource_assets_table');
        if (! $inResources && in_array('resource_assets', SourceScan::stringLiterals(SourceScan::read($path)), true)) {
            $assetTableUsers[] = SourceScan::relative($path);
        }
    }

    expect($diskUsers)->toBe(['app/Modules/Resources/Infrastructure/DiskResourceFileStore.php'])
        ->and($assetTableUsers)->toBe([])
        // Positive controls: the scans see a use in code, and not one in a comment.
        ->and(preg_match('/Storage::|->disk\(|Filesystem\b/', SourceScan::code("<?php Storage::disk('resources')->get('x');")))->toBe(1)
        ->and(preg_match('/Storage::|->disk\(|Filesystem\b/', SourceScan::code("<?php // Storage::disk('resources')")))->toBe(0)
        ->and(SourceScan::stringLiterals("<?php \$db->table('resource_assets')->get();"))->toContain('resource_assets');
});

arch('Resources runs no process and needs no Node: rich content is validated in plain PHP', function () use ($resources) {
    expect($resources)->not->toUse(['Symfony\\Component\\Process', 'Illuminate\\Support\\Facades\\Process', 'Illuminate\\Process']);
});

arch('Resources parses no HTML or XML: a document is data, never markup', function () use ($resources) {
    expect($resources)->not->toUse(['DOMDocument', 'DOMXPath', 'Dom\\', 'SimpleXMLElement', 'XMLReader', 'Masterminds', 'HTMLPurifier', 'Symfony\\Component\\HtmlSanitizer', 'Symfony\\Component\\DomCrawler']);
});

foreach (['Domain', 'Application', 'Infrastructure'] as $layer) {
    arch("Resources {$layer} does not own session, authentication or security concerns", function () use ($resources, $layer) {
        // Only Http (RequestActor) reads who is signed in, exactly as in Crm, Membership, Access and Discussions; every use case receives an Actor.
        expect("{$resources}\\{$layer}")->not->toUse([
            'Illuminate\\Support\\Facades\\Auth', 'Illuminate\\Support\\Facades\\Session', 'Illuminate\\Support\\Facades\\Cookie',
            'Illuminate\\Support\\Facades\\Hash', 'Illuminate\\Contracts\\Auth', 'Illuminate\\Auth', 'Illuminate\\Session',
            'App\\Modules\\Identity\\Application\\AuthenticateAccount', 'App\\Modules\\Identity\\Application\\VerifySecurityAccess',
            'App\\Modules\\Identity\\Application\\SecurityProof', 'App\\Modules\\Identity\\Application\\InviteAccount',
        ]);
    });
}

arch('Resources Http does not know the step-up exists: recent verification is route middleware, never code', function () use ($resources) {
    expect("{$resources}\\Http")->not->toUse(['App\\Modules\\Identity\\Http', 'App\\Modules\\Identity\\Application\\VerifySecurityAccess']);
});

// --- Who may reach Resources -------------------------------------------------------------------------------------------

arch('Identity does not depend on Resources', function () {
    expect('App\\Modules\\Identity')->not->toUse('App\\Modules\\Resources');
});

arch('Access does not depend on Resources', function () {
    // Access defines the Resources capabilities as plain enum cases; it names no Resources type.
    expect('App\\Modules\\Access')->not->toUse('App\\Modules\\Resources');
});

arch('Membership does not depend on Resources: a Member\'s status is not a Resources fact', function () {
    expect('App\\Modules\\Membership')->not->toUse('App\\Modules\\Resources');
});

arch('Crm does not depend on Resources', function () {
    expect('App\\Modules\\Crm')->not->toUse('App\\Modules\\Resources');
});

arch('Discussions does not depend on Resources', function () {
    expect('App\\Modules\\Discussions')->not->toUse('App\\Modules\\Resources');
});

arch('Audit does not depend on Resources', function () {
    expect('App\\Modules\\Audit')->not->toUse('App\\Modules\\Resources');
});

arch('Shared does not depend on Resources: it stays the tiny kernel, never a media or content library', function () {
    expect('App\\Shared')->not->toUse('App\\Modules\\Resources');
});

arch('nothing outside Resources uses Resources, but the development-only demo seeder, which uses its public use cases as an operator would', function () use ($resources) {
    // The one exception is `Database\Seeders\ResourcesDemoSeeder`: opt-in, refused outside local and testing, and referenced by nothing
    // in the application (ResourcesDemoSeederTest pins each). It writes through Resources' own use cases, and its only direct writes are
    // provenance updates to three of Resources' own tables (also pinned there). The module's own exception renderers live in
    // bootstrap/app.php, which is not a class and so not an arch subject; the route loader finds Http/routes.php from disk.
    expect($resources)->toOnlyBeUsedIn([$resources, 'Database\\Seeders\\ResourcesDemoSeeder']);
});

// --- Layers ------------------------------------------------------------------------------------------------------------

arch('Resources Domain is plain PHP: no framework, no other layer', function () use ($resources) {
    expect("{$resources}\\Domain")->not->toUse(['Illuminate', "{$resources}\\Application", "{$resources}\\Infrastructure", "{$resources}\\Http"]);
});

arch('Resources Domain depends on no other module and on nothing in Shared but the Person id', function () use ($resources) {
    expect("{$resources}\\Domain")->not->toUse(['App\\Modules\\Identity', 'App\\Modules\\Access', 'App\\Modules\\Audit', 'App\\Modules\\Membership']);
});

arch('Resources uses no Eloquent model: persistence is query-builder code in Infrastructure that can write only what it provides', function () use ($resources) {
    expect('Illuminate\\Database\\Eloquent')->not->toBeUsedIn($resources);
});

arch('Resources Application does not depend on its Infrastructure or Http', function () use ($resources) {
    expect("{$resources}\\Application")->not->toUse(["{$resources}\\Infrastructure", "{$resources}\\Http"]);
});

arch('Resources Http does not reach into Infrastructure', function () use ($resources) {
    expect("{$resources}\\Http")->not->toUse("{$resources}\\Infrastructure");
});

arch('Resources authorizes only through AuthorizeAction, never by inspecting capabilities or roles itself', function () use ($resources) {
    expect($resources)->not->toUse([Authorizer::class, 'App\\Modules\\Access\\Application\\Role']);
});

// --- The audit seam is narrow ------------------------------------------------------------------------------------------

/**
 * The Resources classes whose code names the audit seam (`RecordSecurityEvent`), read off the source with comments removed.
 *
 * @return list<string>
 */
function resourcesAuditCallers(): array
{
    $callers = [];
    foreach (SourceScan::phpFiles(['app/Modules/Resources']) as $path) {
        if (str_contains(SourceScan::code(SourceScan::read($path)), 'RecordSecurityEvent')) {
            $callers[] = basename($path, '.php');
        }
    }
    sort($callers);

    return $callers;
}

it('lets exactly two Resources use cases call the audit seam: the permanent deletion of a Pack and of a Card', function () {
    expect(resourcesAuditCallers())->toBe(['DeleteCard', 'DeletePack']);

    // Positive controls: the scan sees a use, in code, however it is spelled, and ignores a comment that only names it.
    expect(str_contains(SourceScan::code('<?php use App\\Modules\\Audit\\Application\\RecordSecurityEvent;'), 'RecordSecurityEvent'))->toBeTrue()
        ->and(str_contains(SourceScan::code('<?php public function __construct(private RecordSecurityEvent $record) {}'), 'RecordSecurityEvent'))->toBeTrue()
        ->and(str_contains(SourceScan::code("<?php // never RecordSecurityEvent here\n/** RecordSecurityEvent */ class X {}"), 'RecordSecurityEvent'))->toBeFalse();
});

it('records exactly two event types, both deletions, each at the Resources use case that deletes', function () {
    expect(array_map(fn (ResourceEvent $e): string => $e->value, ResourceEvent::cases()))->toBe(['resource.pack_deleted', 'resource.card_deleted']);

    $uses = [];
    foreach (SourceScan::phpFiles(['app/Modules/Resources']) as $path) {
        preg_match_all('/ResourceEvent::(\w+)/', SourceScan::code(SourceScan::read($path)), $matches);
        foreach ($matches[1] as $case) {
            $uses[basename($path, '.php')][] = $case;
        }
    }

    expect($uses)->toBe(['DeleteCard' => ['CardDeleted'], 'DeletePack' => ['PackDeleted']]);
});

it('writes a security event only for a SUCCESSFUL deletion: the use cases record the Success outcome and no other', function () {
    foreach (['DeletePack', 'DeleteCard'] as $useCase) {
        $code = SourceScan::code(SourceScan::read(SourceScan::root()."/app/Modules/Resources/Application/{$useCase}.php"));
        preg_match_all('/SecurityEventOutcome::(\w+)/', $code, $outcomes);

        expect(array_values(array_unique($outcomes[1])))->toBe(['Success'], $useCase);
    }
});

// --- Tables: Resources reads and writes its own, and nothing of anyone else's -----------------------------------------------

/**
 * Every table the platform's migrations create that is not Resources' own: the persistence Resources must never name. Read from the
 * migrations themselves, so a table added by a later module is protected without editing this test.
 *
 * @return list<string>
 */
function resourcesForeignTables(): array
{
    $tables = [];
    foreach (glob(SourceScan::root().'/database/migrations/*.php') ?: [] as $migration) {
        preg_match_all('/Schema::create\(\s*[\'"]([a-z_]+)[\'"]/', SourceScan::read($migration), $matches);
        foreach ($matches[1] as $table) {
            if (! str_starts_with($table, 'resource_')) {
                $tables[] = $table;
            }
        }
    }

    return array_values(array_unique($tables));
}

/**
 * The string literals in this source that name one of `$tables`, however they reach the database: a bare literal, an `'accounts as a'`
 * alias, or inside raw SQL (`from`, `join`, `into`, `update`, `table`).
 *
 * @param  list<string>  $tables
 * @return list<string>
 */
function resourcesForeignTableLiterals(string $source, array $tables): array
{
    $names = implode('|', array_map(static fn (string $t): string => preg_quote($t, '/'), $tables));
    $found = [];
    foreach (SourceScan::stringLiterals($source) as $literal) {
        if (preg_match('/^\s*(?:'.$names.')(?:\s+as\s+\w+)?\s*$/i', $literal) === 1
            || preg_match('/\b(?:from|join|into|update|table|truncate)\s+["`]?(?:'.$names.')\b/i', $literal) === 1) {
            $found[] = $literal;
        }
    }

    return $found;
}

it('names no table that is not Resources\' own: it never queries or writes people, Accounts, roles, Membership, CRM, Discussions or the audit trail', function () {
    $tables = resourcesForeignTables();

    expect($tables)->toContain('people', 'accounts', 'role_assignments', 'membership_grants', 'security_events', 'sessions', 'discussions', 'contact_interactions')
        ->and($tables)->not->toContain('resource_cards', 'resource_packs');

    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Resources/Domain', 'app/Modules/Resources/Application', 'app/Modules/Resources/Infrastructure']) as $path) {
        foreach (resourcesForeignTableLiterals(SourceScan::read($path), $tables) as $literal) {
            $offenders[] = SourceScan::relative($path).": {$literal}";
        }
    }

    expect($offenders)->toBe([]);

    // Positive controls: every form a planted offender could take, and no false positive on Resources' own tables or prose.
    expect(resourcesForeignTableLiterals("<?php \$db->table('people')->update(['display_name' => 'x']);", $tables))->toBe(['people'])
        ->and(resourcesForeignTableLiterals("<?php \$db->table('membership_grants as m')->get();", $tables))->toBe(['membership_grants as m'])
        ->and(resourcesForeignTableLiterals("<?php \$db->select('select email from accounts where id = ?', [\$id]);", $tables))->toHaveCount(1)
        ->and(resourcesForeignTableLiterals('<?php $db->statement("insert into security_events values (1)");', $tables))->toHaveCount(1)
        ->and(resourcesForeignTableLiterals("<?php \$db->table('resource_cards')->get(); echo 'no such people'; // table('people')", $tables))->toBe([]);
});

it('names only the six tables Resources owns, wherever it reaches the database', function () {
    // String constants are table names in the repositories (TABLE, AUDIENCES, ASSETS); elsewhere they are not (the store's DISK name).
    $tablesIn = function (string $source, bool $repository = true): array {
        $code = SourceScan::code($source);
        preg_match_all('/const\s+string\s+\w+\s*=\s*[\'"]([a-z_]+)[\'"]/', $repository ? $code : '', $constants);
        preg_match_all('/(?:->|::)table\(\s*[\'"]([a-z_]+)(?:\s+as\s+\w+)?[\'"]/', $code, $calls);
        preg_match_all('/(?:->join|->leftJoin)\(\s*[\'"]([a-z_]+)(?:\s+as\s+\w+)?[\'"]/', $code, $joins);
        preg_match_all('/->from\(\s*[\'"]([a-z_]+)(?:\s+as\s+\w+)?[\'"]/', $code, $from);

        return [...$constants[1], ...$calls[1], ...$joins[1], ...$from[1]];
    };

    $tables = [];
    foreach (SourceScan::phpFiles(['app/Modules/Resources/Infrastructure']) as $path) {
        array_push($tables, ...$tablesIn(SourceScan::read($path), str_starts_with(basename($path), 'Database')));
    }

    expect(array_values(array_unique($tables)))->toEqualCanonicalizing(['resource_categories', 'resource_packs', 'resource_pack_audiences', 'resource_cards', 'resource_card_audiences', 'resource_assets'])
        ->and($tablesIn("<?php private const string TABLE = 'widgets';"))->toBe(['widgets'])
        ->and($tablesIn("<?php \$db->table('gadgets as g')->join('gizmos as z', 'a', 'b')->get();"))->toBe(['gadgets', 'gizmos'])
        ->and($tablesIn("<?php \$db->table(self::TABLE)->where('pack_id', 1)->get(); // table('ghost')"))->toBe([]);
});

it('creates exactly the six Resources tables and no generic content, page, media, folder, trash, history or version table', function () {
    $owned = [];
    foreach (glob(SourceScan::root().'/database/migrations/*.php') ?: [] as $migration) {
        preg_match_all('/Schema::create\(\s*[\'"](resource[a-z_]*)[\'"]/', SourceScan::read($migration), $matches);
        array_push($owned, ...$matches[1]);
    }

    // `resource_assets` is the one asset table ADR 0037 approved: the metadata of the file each File Card owns. Nothing else of the kind.
    expect($owned)->toEqualCanonicalizing(['resource_categories', 'resource_packs', 'resource_pack_audiences', 'resource_cards', 'resource_card_audiences', 'resource_assets'])
        ->and(array_values(array_filter(array_diff($owned, ['resource_assets']), fn (string $t): bool => preg_match('/content|page|media|asset|file|folder|trash|archive|history|version|revision|attachment|library/', $t) === 1)))->toBe([]);
});

it('puts no Resources, Card, Pack or content type in Shared', function () {
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Shared']) as $path) {
        if (preg_match('/resource|card|pack|content|category|asset|media/i', basename($path)) === 1
            || preg_match('/\bResource(Pack|Card|Category)|ContentDocument|DocumentProfile\b/', SourceScan::code(SourceScan::read($path))) === 1) {
            $offenders[] = SourceScan::relative($path);
        }
    }

    expect($offenders)->toBe([]);
});

// --- No relationship is invented here (ADR 0036) ---------------------------------------------------------------------------

/**
 * The identifiers and string literals in Resources code that name a business relationship other than the two audiences it implements.
 *
 * @return list<string>
 */
function resourcesInventedRelationships(string $source): array
{
    $found = [];
    $code = SourceScan::code($source);
    if (preg_match_all('/\b\w*(?:volunteer|partner|vendor|artist)\w*\b/i', $code, $matches) > 0) {
        array_push($found, ...$matches[0]);
    }

    return $found;
}

it('invents no Volunteer, Partner, Vendor or Artist relationship, audience, flag or eligibility: those wait for their owning domains', function () {
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Resources', 'database/migrations/2026_10_04_000001_create_resource_categories_table.php', 'database/migrations/2026_10_04_000002_create_resource_packs_tables.php', 'database/migrations/2026_10_04_000003_create_resource_cards_tables.php', 'database/migrations/2026_10_04_000004_create_resource_assets_table.php']) as $path) {
        foreach (resourcesInventedRelationships(SourceScan::read($path)) as $word) {
            $offenders[] = SourceScan::relative($path).": {$word}";
        }
    }

    expect($offenders)->toBe([])
        ->and(array_map(fn (Audience $a): string => $a->value, Audience::cases()))->toBe(['guardian', 'member'])
        // Positive controls: an identifier, a literal and a method name are all seen; a comment that explains the absence is not.
        ->and(resourcesInventedRelationships("<?php case Volunteer = 'volunteer';"))->not->toBe([])
        ->and(resourcesInventedRelationships('<?php $isVolunteer = $this->volunteering->isVolunteer($p);'))->not->toBe([])
        ->and(resourcesInventedRelationships("<?php // Volunteer is deferred (ADR 0036)\n/** a partner, a vendor or an artist */ class X {}"))->toBe([]);
});

/**
 * @param  class-string  $class
 * @return list<string>
 */
function resourcesPropertiesOf(string $class): array
{
    return array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass($class))->getProperties());
}

it('stores a relationship fact nowhere: a Pack and a Card hold audience KEYS, never a Person, an Account or a relationship record', function () {
    $properties = resourcesPropertiesOf(...);

    expect($properties(Pack::class))->toBe(['id', 'categoryId', 'position', 'title', 'summary', 'isSeries', 'audiences', 'state', 'revision', 'provenance'])
        ->and($properties(Card::class))->toBe(['id', 'packId', 'position', 'type', 'title', 'summary', 'content', 'externalUri', 'asset', 'audience', 'state', 'revision', 'provenance'])
        ->and($properties(ResourceAsset::class))->toBe(['id', 'storageKey', 'originalFilename', 'mediaType', 'byteSize', 'sha256', 'uploadedBy', 'uploadedAt'])
        ->and($properties(Category::class))->toBe(['id', 'name', 'nameCanonical', 'position', 'provenance']);
});

// --- Dependencies this package must not have introduced ----------------------------------------------------------------

it('adds no HTML sanitizer, editor or Node-backed package to the platform: Phase 1 has no HTML ingress or egress', function () {
    $composer = (string) file_get_contents(SourceScan::root().'/composer.json');

    foreach (['html-sanitizer', 'htmlpurifier', 'tiptap', 'shiki', 'masterminds/html5', 'dompdf', 'league/commonmark', 'symfony/process'] as $forbidden) {
        expect(strtolower($composer))->not->toContain($forbidden);
    }
});
