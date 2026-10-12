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
use App\Modules\Resources\Application\ViewerLibrary;
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
            expect(str_contains($code, $name))->toBeFalse("{$class} names {$name}");
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
            expect(str_contains($code, $name))->toBeFalse("{$class} names {$name}");
        }
        expect(preg_match('/use App\\\\Modules\\\\\w+\\\\Http\\\\/', $code))->toBe(0, "{$class} imports an Http class");
    }
});

// These two read models perform no capability check of their own: the three use cases in front of each are the whole of its authority
// (`resources.view` for ViewerLibrary, the Actor's own eligibility for AudienceDelivery). So WHO MAY CALL THEM is the boundary. Both are
// autowired (no service-provider wiring names them), so each list is exactly the classes that approved use cases, and Pest's arch layer
// resolves every spelling of a reference (import, grouped import, alias, namespace alias, same namespace) to the class it names.
arch('privileged viewing\'s read model has exactly three callers, the use cases that ask for resources.view (R5, R15)', function () {
    expect(ViewerLibrary::class)->toOnlyBeUsedIn([BrowseLibrary::class, ReadLibraryPack::class, DownloadLibraryFile::class]);
});

arch('audience delivery has exactly three callers, the policy C use cases (R11, R15)', function () {
    expect(AudienceDelivery::class)->toOnlyBeUsedIn([BrowseDeliveredResources::class, GetDeliveredPack::class, DownloadDeliveredFile::class]);
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
//
// These are `arch()` rules, not text scans: Pest resolves every reference in a class (an import, a grouped import, a class alias, a
// namespace alias, a fully qualified name, a same-namespace reference) to the class it names, so a dependency cannot be hidden by
// how it is spelled. The reading policies are allowlists (`toOnlyUse`): a reader controller may name its own policy, the neutral HTTP
// edge shared by all three, and the framework. Management is checked the other way round, because it legitimately names a great deal
// of its own.

/** The neutral edge every family shares and which carries no authority of its own: the caller, the file request and the file on the wire. */
const RESOURCES_NEUTRAL_EDGE = [
    'App\\Modules\\Resources\\Http\\DownloadFileRequest',
    'App\\Modules\\Resources\\Http\\FileResponse',
    'App\\Modules\\Resources\\Http\\RequestActor',
];

/**
 * The framework classes a controller or request may name, and the `response()` helper (which the arch layer counts as a dependency). Nothing under
 * `App\` is here, so no other module and no other Resources layer slips in.
 */
const RESOURCES_FRAMEWORK = [
    'Illuminate\\Contracts\\Auth\\Authenticatable', 'Illuminate\\Foundation\\Http\\FormRequest', 'Illuminate\\Http\\JsonResponse', 'Illuminate\\Http\\Request',
    'Illuminate\\Validation\\Rule', 'Symfony\\Component\\HttpFoundation\\StreamedResponse', 'response',
];

/** @return list<string> everything a Resources reading policy's own classes may name beyond the edge: the identifiers they are given and the framework */
function resourcesAllowedFor(string ...$own): array
{
    return array_values([...$own, ...RESOURCES_NEUTRAL_EDGE, ...RESOURCES_FRAMEWORK]);
}

arch('a policy A controller names only policy A, the neutral edge and the identifiers it is given: nothing of policy B or C (R15)', function () {
    $http = 'App\\Modules\\Resources\\Http\\';
    $app = 'App\\Modules\\Resources\\Application\\';
    $domain = 'App\\Modules\\Resources\\Domain\\';

    expect($http.'BrowseLibraryController')->toOnlyUse(resourcesAllowedFor($app.'BrowseLibrary', $http.'LibraryRequest', $http.'ViewerPresenter'));
    expect($http.'ShowLibraryPackController')->toOnlyUse(resourcesAllowedFor($app.'ReadLibraryPack', $domain.'PackId', $http.'ViewerPresenter'));
    expect($http.'DownloadLibraryFileController')->toOnlyUse(resourcesAllowedFor($app.'DownloadLibraryFile', $domain.'CardId', $domain.'PackId'));
    // The audience here is a FILTER over Packs, validated and only narrowing policy A.
    expect($http.'LibraryRequest')->toOnlyUse(resourcesAllowedFor($app.'LibraryFilter', $domain.'Audience', $domain.'CategoryId', $domain.'PublicationFilter', $domain.'ResourceText'));
});

arch('a policy C controller names only policy C, the neutral edge and the identifiers it is given: nothing of policy A or B (R15)', function () {
    $http = 'App\\Modules\\Resources\\Http\\';
    $app = 'App\\Modules\\Resources\\Application\\';
    $domain = 'App\\Modules\\Resources\\Domain\\';

    expect($http.'BrowseMyResourcesController')->toOnlyUse(resourcesAllowedFor($app.'BrowseDeliveredResources', $domain.'CategoryId', $http.'DeliveryPresenter', $http.'MyResourcesRequest'));
    expect($http.'ShowMyResourcePackController')->toOnlyUse(resourcesAllowedFor($app.'GetDeliveredPack', $domain.'PackId', $http.'DeliveryPresenter'));
    expect($http.'DownloadMyResourceFileController')->toOnlyUse(resourcesAllowedFor($app.'DownloadDeliveredFile', $domain.'CardId', $domain.'PackId'));
    expect($http.'MyResourcesRequest')->toOnlyUse(resourcesAllowedFor($domain.'CategoryId', $domain.'ResourceText'));
});

/** @return list<class-string> the management controllers: every Resources controller that is not one of the six reader controllers */
function resourcesManagementControllers(): array
{
    $readers = ['BrowseLibraryController', 'ShowLibraryPackController', 'DownloadLibraryFileController', 'BrowseMyResourcesController', 'ShowMyResourcePackController', 'DownloadMyResourceFileController'];
    $controllers = [];
    foreach (SourceScan::phpFiles([RESOURCES_ROOT.'/Http']) as $path) {
        $class = basename($path, '.php');
        if (str_ends_with($class, 'Controller') && ! in_array($class, $readers, true)) {
            /** @var class-string $name */
            $name = 'App\\Modules\\Resources\\Http\\'.$class;
            $controllers[] = $name;
        }
    }

    return $controllers;
}

/** @return list<string> every class of the two reading policies: their use cases, read models, requests, presenters and result types */
function resourcesReadingClasses(): array
{
    $app = 'App\\Modules\\Resources\\Application\\';
    $http = 'App\\Modules\\Resources\\Http\\';

    return [
        ...array_map(fn (string $c): string => $app.$c, [
            'ViewerLibrary', 'BrowseLibrary', 'ReadLibraryPack', 'DownloadLibraryFile', 'ViewerCard', 'ViewerCategory', 'ViewerPack', 'ViewerPackSummary', 'LibraryFilter',
            'AudienceEligibility', 'AudienceDelivery', 'BrowseDeliveredResources', 'GetDeliveredPack', 'DownloadDeliveredFile', 'DeliveredCard', 'DeliveredCategory', 'DeliveredPack', 'DeliveredListedPack', 'DeliveredPacks',
        ]),
        ...array_map(fn (string $c): string => $http.$c, ['ViewerPresenter', 'DeliveryPresenter', 'LibraryRequest', 'MyResourcesRequest']),
    ];
}

arch('a management controller names neither reading policy: no viewer, delivery or eligibility class (R15)', function () {
    $controllers = resourcesManagementControllers();

    expect(count($controllers))->toBeGreaterThan(20);
    // One controller per expectation, on purpose: Pest's `not->toUse` over a LIST of targets fails only when EVERY target uses the
    // dependency, so a list would let a single offending controller through.
    foreach ($controllers as $controller) {
        expect($controller)->not->toUse(resourcesReadingClasses());
    }
});

arch('the management presenter reaches delivery only for preview\'s shape, and never the privileged viewer or eligibility', function () {
    $presenter = 'App\\Modules\\Resources\\Http\\ResourcesPresenter';
    $app = 'App\\Modules\\Resources\\Application\\';

    expect($presenter)->not->toUse(['App\\Modules\\Resources\\Http\\ViewerPresenter', $app.'ViewerLibrary', $app.'AudienceEligibility', $app.'AudienceDelivery', $app.'BrowseDeliveredResources', $app.'GetDeliveredPack', $app.'DownloadDeliveredFile']);
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
            expect(str_contains(strtolower($code), $word))->toBeFalse("{$request} names {$word}");
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
