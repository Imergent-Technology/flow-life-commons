<?php

declare(strict_types=1);

use App\Modules\Resources\Application\AudienceDelivery;
use App\Modules\Resources\Application\AudienceEligibility;
use App\Modules\Resources\Application\BrowseDeliveredResources;
use App\Modules\Resources\Application\BrowseLibrary;
use App\Modules\Resources\Application\DownloadDeliveredFile;
use App\Modules\Resources\Application\DownloadLibraryFile;
use App\Modules\Resources\Application\GetDeliveredPack;
use App\Modules\Resources\Application\LibraryFilter;
use App\Modules\Resources\Application\ReadLibraryPack;
use App\Modules\Resources\Http\BrowseMyResourcesController;
use App\Modules\Resources\Http\DownloadMyResourceFileController;
use App\Modules\Resources\Http\ShowMyResourcePackController;
use Tests\Support\SourceScan;

/*
 * The separation of Resources' three access policies (ADR 0038, R4, R5, R11, R14, R15), as source structure. The runtime proofs (who reads
 * what, eligibility, revocation, non-disclosure) are the feature tests under tests/Feature/Modules/Resources.
 *
 *   Policy A, privileged viewing: `ViewerLibrary` and `BrowseLibrary`, `ReadLibraryPack`, `DownloadLibraryFile`; authority `resources.view`.
 *   Policy B, management:         everything else under /admin/resources; authority `resources.manage`.
 *   Policy C, audience reading:   `AudienceEligibility`, `AudienceDelivery` and `BrowseDeliveredResources`, `GetDeliveredPack`,
 *                                 `DownloadDeliveredFile`; authority the signed-in Person's own relationships.
 *
 * Every source scan has a positive control, and a scan reads code with comments removed (README.md).
 */

const RESOURCES_ROOT = 'app/Modules/Resources';

/**
 * The Resources classes a source file imports or names, as short "Layer\Class" names (comments removed).
 *
 * @return list<string>
 */
function resourcesPolicyTypes(string $source): array
{
    preg_match_all('/App\\\\Modules\\\\Resources\\\\([A-Za-z\\\\]+)/', SourceScan::code($source), $matches);
    // A bare layer name is the file's own `namespace` line, not a dependency.
    $types = array_values(array_diff(array_unique($matches[1]), ['Http', 'Application', 'Domain', 'Infrastructure']));
    sort($types);

    return $types;
}

/** @return list<string> the Resources types a Resources file names */
function resourcesPolicyTypesOf(string $relative): array
{
    return resourcesPolicyTypes(SourceScan::read(SourceScan::root().'/'.RESOURCES_ROOT.'/'.$relative.'.php'));
}

/**
 * Every Resources file (relative, without extension) whose code mentions `$needle`.
 *
 * @return list<string>
 */
function resourcesPolicyMentions(string $needle): array
{
    $files = [];
    foreach (SourceScan::phpFiles([RESOURCES_ROOT]) as $path) {
        if (str_contains(SourceScan::code(SourceScan::read($path)), $needle)) {
            $files[] = substr(SourceScan::relative($path), strlen(RESOURCES_ROOT) + 1, -4);
        }
    }

    return $files;
}

// --- The projection and the eligibility seam ---------------------------------------------------------------------------------

it('lets only audience delivery and preview call the projection (R15): ResourceProjection::visibleCards has exactly two callers', function () {
    $callers = [];
    foreach (SourceScan::phpFiles(['app', 'database', 'routes', 'bootstrap']) as $path) {
        if (preg_match('/visibleCards\s*\(/', SourceScan::code(SourceScan::read($path))) === 1) {
            $callers[] = SourceScan::relative($path);
        }
    }

    expect($callers)->toBe([
        'app/Modules/Resources/Application/AudienceDelivery.php',
        'app/Modules/Resources/Application/PreviewPack.php',
        // The definition itself.
        'app/Modules/Resources/Domain/ResourceProjection.php',
    ])
        ->and(preg_match('/visibleCards\s*\(/', SourceScan::code('<?php $v = ResourceProjection::visibleCards($p, $o, $s);')))->toBe(1)
        ->and(preg_match('/visibleCards\s*\(/', SourceScan::code('<?php // never visibleCards( here')))->toBe(0);
});

arch('audience eligibility is used only by audience delivery: nothing else produces policy C\'s audience set (R15)', function () {
    expect(AudienceEligibility::class)->toOnlyBeUsedIn([AudienceDelivery::class]);
});

it('feeds audience delivery its audiences from AudienceEligibility and from nothing else: it builds, accepts and derives none itself', function () {
    $code = SourceScan::code(SourceScan::read(SourceScan::root().'/'.RESOURCES_ROOT.'/Application/AudienceDelivery.php'));

    // No AudienceSet is made or parameterised there, no Audience case is named, and its public methods take an Actor, never a set.
    expect($code)->toContain('AudienceEligibility')
        ->and($code)->not->toMatch('/AudienceSet::(of|fromList)\s*\(/')
        ->and($code)->not->toContain('Audience::')
        ->and($code)->not->toMatch('/function\s+\w+\([^)]*AudienceSet/')
        ->and((new ReflectionMethod(AudienceDelivery::class, 'library'))->getParameters()[0]->getType()?->__toString())->toBe('App\\Shared\\Domain\\Actor')
        ->and((new ReflectionMethod(AudienceDelivery::class, 'visible'))->getParameters()[0]->getType()?->__toString())->toBe('App\\Shared\\Domain\\Actor')
        // Positive controls: the patterns do see a built set and a set parameter.
        ->and(preg_match('/AudienceSet::(of|fromList)\s*\(/', 'x = AudienceSet::of(Audience::Guardian);'))->toBe(1)
        ->and(preg_match('/function\s+\w+\([^)]*AudienceSet/', 'public function visible(AudienceSet $a, PackId $id)'))->toBe(1);
});

it('takes nothing but the Actor into audience eligibility: no audience, Person, Account or mode from its caller (R10)', function () {
    $parameters = (new ReflectionMethod(AudienceEligibility::class, '__invoke'))->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getType()?->__toString())->toBe('App\\Shared\\Domain\\Actor');
});

// --- Policy A reads neither the projection nor eligibility ---------------------------------------------------------------------

it('keeps privileged viewing off the projection, eligibility, relationships, Membership and policy C altogether (R5, R15)', function () {
    $policyA = ['ViewerLibrary', 'BrowseLibrary', 'ReadLibraryPack', 'DownloadLibraryFile', 'ViewerCard', 'ViewerCategory', 'ViewerPack', 'ViewerPackSummary', 'LibraryFilter'];
    $forbidden = ['ResourceProjection', 'AudienceEligibility', 'AudienceDelivery', 'DeliveredPacks', 'DeliveredPack', 'BrowseDeliveredResources', 'GetDeliveredPack', 'DownloadDeliveredFile', 'QualifyingRelationships', 'GetCurrentMembership'];

    foreach ($policyA as $class) {
        $code = SourceScan::code(SourceScan::read(SourceScan::root().'/'.RESOURCES_ROOT."/Application/{$class}.php"));
        foreach ($forbidden as $name) {
            expect($code)->not->toContain($name, "{$class} names {$name}");
        }
    }
    // A planted use would be seen.
    expect(str_contains(SourceScan::code('<?php ResourceProjection::visibleCards($a, $b, $c);'), 'ResourceProjection'))->toBeTrue();
});

it('lets policy A authorize by resources.view alone: its use cases name that capability and no other, and it never names a role', function () {
    foreach (['BrowseLibrary', 'ReadLibraryPack', 'DownloadLibraryFile'] as $useCase) {
        $code = SourceScan::code(SourceScan::read(SourceScan::root().'/'.RESOURCES_ROOT."/Application/{$useCase}.php"));
        preg_match_all('/Capability::(\w+)/', $code, $matches);

        expect(array_values(array_unique($matches[1])))->toBe(['ViewResources'], $useCase);
    }
});

// --- Policy C is independent of the Console, capabilities and roles ---------------------------------------------------------

it('keeps audience reading independent of the Console, capabilities, roles and admission: a future surface reuses it unchanged (R15)', function () {
    $policyC = ['AudienceEligibility', 'AudienceDelivery', 'BrowseDeliveredResources', 'GetDeliveredPack', 'DownloadDeliveredFile'];

    foreach ($policyC as $class) {
        $source = SourceScan::read(SourceScan::root().'/'.RESOURCES_ROOT."/Application/{$class}.php");
        $code = SourceScan::code($source);

        // No capability or authorization seam, no role, no Console or HTTP class, no Console admission string.
        foreach (['Capability', 'AuthorizeAction', 'AccessDenied', 'Authorizer', 'Role', 'Console', 'console.access', 'ConsoleAccess', 'GuardianFull', 'guardian-', 'resources.view', 'resources.manage', 'ViewResources', 'ManageResources', 'Http'] as $name) {
            expect($code)->not->toContain($name, "{$class} names {$name}");
        }
        expect(preg_match('/use App\\\\Modules\\\\\w+\\\\Http\\\\/', $code))->toBe(0, "{$class} imports an Http class");
    }
});

arch('policy C\'s use cases are used only by the my-resources controllers, the approved adapters of G10 (R15)', function () {
    expect(BrowseDeliveredResources::class)->toOnlyBeUsedIn([BrowseMyResourcesController::class]);
    expect(GetDeliveredPack::class)->toOnlyBeUsedIn([ShowMyResourcePackController::class]);
    expect(DownloadDeliveredFile::class)->toOnlyBeUsedIn([DownloadMyResourceFileController::class]);
});

arch('policy A\'s use cases are used only by the privileged-library controllers', function () {
    expect(BrowseLibrary::class)->toOnlyBeUsedIn(['App\\Modules\\Resources\\Http\\BrowseLibraryController']);
    expect(ReadLibraryPack::class)->toOnlyBeUsedIn(['App\\Modules\\Resources\\Http\\ShowLibraryPackController']);
    expect(DownloadLibraryFile::class)->toOnlyBeUsedIn(['App\\Modules\\Resources\\Http\\DownloadLibraryFileController']);
});

// --- The controllers of the three families cannot reach each other ---------------------------------------------------------------

/** The Resources types an HTTP adapter of one policy may name: its own, and the neutral edge shared by all three. */
const RESOURCES_NEUTRAL_HTTP = ['Http\\DownloadFileRequest', 'Http\\FileResponse', 'Http\\RequestActor', 'Http\\BlankInput'];

it('lets a policy C controller name only policy C, the neutral HTTP edge and the identifiers it is given: nothing of policy A or B (R15)', function () {
    $allowed = [
        'BrowseMyResourcesController' => ['Application\\BrowseDeliveredResources', 'Domain\\CategoryId', 'Http\\DeliveryPresenter', 'Http\\MyResourcesRequest', ...RESOURCES_NEUTRAL_HTTP],
        'ShowMyResourcePackController' => ['Application\\GetDeliveredPack', 'Domain\\PackId', 'Http\\DeliveryPresenter', ...RESOURCES_NEUTRAL_HTTP],
        'DownloadMyResourceFileController' => ['Application\\DownloadDeliveredFile', 'Domain\\CardId', 'Domain\\PackId', ...RESOURCES_NEUTRAL_HTTP],
        'MyResourcesRequest' => ['Domain\\CategoryId', 'Domain\\ResourceText'],
    ];

    foreach ($allowed as $class => $types) {
        $named = resourcesPolicyTypesOf("Http/{$class}");
        $outside = array_values(array_diff($named, $types));

        expect($outside)->toBe([], "{$class} names types outside policy C: ".implode(', ', $outside));
    }
});

it('lets a policy A controller name only policy A, the neutral HTTP edge and the identifiers it is given: nothing of policy B or C (R15)', function () {
    $allowed = [
        'BrowseLibraryController' => ['Application\\BrowseLibrary', 'Http\\LibraryRequest', 'Http\\ViewerPresenter', ...RESOURCES_NEUTRAL_HTTP],
        'ShowLibraryPackController' => ['Application\\ReadLibraryPack', 'Domain\\PackId', 'Http\\ViewerPresenter', ...RESOURCES_NEUTRAL_HTTP],
        'DownloadLibraryFileController' => ['Application\\DownloadLibraryFile', 'Domain\\CardId', 'Domain\\PackId', ...RESOURCES_NEUTRAL_HTTP],
        // The audience here is a FILTER over Packs, validated and only narrowing policy A.
        'LibraryRequest' => ['Application\\LibraryFilter', 'Domain\\Audience', 'Domain\\CategoryId', 'Domain\\PublicationFilter', 'Domain\\ResourceText'],
    ];

    foreach ($allowed as $class => $types) {
        $named = resourcesPolicyTypesOf("Http/{$class}");
        $outside = array_values(array_diff($named, $types));

        expect($outside)->toBe([], "{$class} names types outside policy A: ".implode(', ', $outside));
    }
    // Positive control: a policy C type in a policy A controller would be seen.
    expect(resourcesPolicyTypes('<?php use App\\Modules\\Resources\\Application\\GetDeliveredPack; use App\\Modules\\Resources\\Http\\DeliveryPresenter;'))->toBe(['Application\\GetDeliveredPack', 'Http\\DeliveryPresenter']);
});

it('keeps policy B\'s management controllers off both reading policies: none names a viewer or delivery class', function () {
    $reading = ['ViewerLibrary', 'ViewerPresenter', 'DeliveryPresenter', 'AudienceEligibility', 'AudienceDelivery', 'BrowseLibrary', 'ReadLibraryPack', 'DownloadLibraryFile', 'BrowseDeliveredResources', 'GetDeliveredPack', 'DownloadDeliveredFile', 'MyResourcesRequest', 'LibraryRequest'];
    $offenders = [];
    $readers = ['BrowseLibraryController', 'ShowLibraryPackController', 'DownloadLibraryFileController', 'BrowseMyResourcesController', 'ShowMyResourcePackController', 'DownloadMyResourceFileController', 'LibraryRequest', 'MyResourcesRequest', 'ViewerPresenter', 'DeliveryPresenter'];
    foreach (SourceScan::phpFiles([RESOURCES_ROOT.'/Http']) as $path) {
        $class = basename($path, '.php');
        // Preview is management's, and delegates to the delivery presenter for its shape alone (ResourcesPresenter).
        if (in_array($class, $readers, true) || $class === 'ResourcesPresenter') {
            continue;
        }
        foreach ($reading as $name) {
            if (preg_match('/\b'.$name.'\b/', SourceScan::code(SourceScan::read($path))) === 1) {
                $offenders[] = "{$class}: {$name}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

// --- Requests: no client-chosen audience, Person or mode ---------------------------------------------------------------------

/** @return list<string> the keys a request class validates, read from its `rules()` array literal */
function resourcesRequestKeys(string $class): array
{
    $source = SourceScan::code(SourceScan::read(SourceScan::root().'/'.RESOURCES_ROOT."/Http/{$class}.php"));
    preg_match('/function rules\(\): array\s*\{(.*?)\n    \}/s', $source, $body);
    preg_match_all("/'([a-z_.*]+)'\s*=>\s*\[/", $body[1] ?? '', $keys);

    return $keys[1];
}

it('accepts no audience set, Person, Account or mode in the library or my-resources requests: only filters, and the audience filter only narrows policy A (R15)', function () {
    expect(resourcesRequestKeys('LibraryRequest'))->toBe(['category', 'q', 'publication', 'audience'])
        ->and(resourcesRequestKeys('MyResourcesRequest'))->toBe(['category', 'q'])
        ->and(resourcesRequestKeys('DownloadFileRequest'))->toBe(['disposition']);

    // Nothing a client sends is read except those keys: no request class reads `person`, `account`, `actor` or `mode`.
    foreach (['LibraryRequest', 'MyResourcesRequest', 'DownloadFileRequest'] as $request) {
        $code = SourceScan::code(SourceScan::read(SourceScan::root().'/'.RESOURCES_ROOT."/Http/{$request}.php"));
        foreach (['person', 'account', 'actor', 'viewer', 'mode', 'audiences'] as $word) {
            expect(strtolower($code))->not->toContain($word, "{$request} names {$word}");
        }
    }
    // Positive control for the key reader.
    expect(preg_match("/'([a-z_.*]+)'\s*=>\s*\[/", "        'person_id' => ['ulid'],", $found))->toBe(1)
        ->and($found[1] ?? null)->toBe('person_id');
});

it('takes no audience, audience set or Person as the authority of any Resource read: reading use cases accept an Actor, ids and filters (R15)', function () {
    $useCases = [
        BrowseLibrary::class => ['Actor', 'LibraryFilter'],
        ReadLibraryPack::class => ['Actor', 'PackId'],
        DownloadLibraryFile::class => ['Actor', 'PackId', 'CardId'],
        BrowseDeliveredResources::class => ['Actor', '?CategoryId', '?string'],
        GetDeliveredPack::class => ['Actor', 'PackId'],
        DownloadDeliveredFile::class => ['Actor', 'PackId', 'CardId'],
    ];

    foreach ($useCases as $class => $expected) {
        $types = array_map(fn (ReflectionParameter $p): string => str_replace(['App\\Shared\\Domain\\', 'App\\Modules\\Resources\\Domain\\', 'App\\Modules\\Resources\\Application\\'], '', (string) $p->getType()), (new ReflectionMethod($class, '__invoke'))->getParameters());

        expect($types)->toBe($expected, $class);
    }
    // The one place an audience appears in a read is the privileged library's filter, which only narrows.
    expect(array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(LibraryFilter::class))->getProperties()))->toBe(['category', 'text', 'publication', 'audience']);
});
