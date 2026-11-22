<?php

declare(strict_types=1);

use Tests\Support\SourceScan;

/*
 * The Relationships module's boundaries (ADR 0038). Modules are discovered from disk, so ModuleBoundariesTest and the
 * frozen graph already cover it. These are the Relationships-specific statements.
 *
 * Relationships may use Access, Identity and Audit through their Application layers, and CRM only through
 * `Crm\Application\Delegated` (WP3, P1): which Relationships class may call which delegated service is pinned in
 * CrmBoundariesTest. QualifyingRelationships has exactly one caller outside the module, Resources' audience eligibility (WP4, F14).
 */

$relationships = 'App\\Modules\\Relationships';

arch('Relationships uses Identity only through its Application layer', function () use ($relationships) {
    expect($relationships)->not->toUse(['App\\Modules\\Identity\\Domain', 'App\\Modules\\Identity\\Infrastructure', 'App\\Modules\\Identity\\Http']);
});

arch('Relationships uses Access only through its Application layer', function () use ($relationships) {
    expect($relationships)->not->toUse(['App\\Modules\\Access\\Domain', 'App\\Modules\\Access\\Infrastructure', 'App\\Modules\\Access\\Http']);
});

arch('Relationships uses Audit only through its Application layer, and only DeleteRelationship records an event', function () use ($relationships) {
    expect($relationships)->not->toUse(['App\\Modules\\Audit\\Domain', 'App\\Modules\\Audit\\Infrastructure', 'App\\Modules\\Audit\\Http']);
    expect('App\\Modules\\Audit\\Application\\RecordSecurityEvent')->toOnlyBeUsedIn([
        'App\\Modules\\Identity', 'App\\Modules\\Access', 'App\\Modules\\Audit',
        'App\\Modules\\Resources\\Application\\DeletePack', 'App\\Modules\\Resources\\Application\\DeleteCard',
        'App\\Modules\\Relationships\\Application\\DeleteRelationship',
    ]);
});

arch('Relationships does not depend on Membership, Resources or WordPress, and takes from CRM nothing but its delegated services', function () use ($relationships) {
    expect($relationships)->not->toUse(['App\\Modules\\Membership', 'App\\Modules\\Resources', 'WP_', 'wp_'])
        ->and($relationships)->not->toUse(['App\\Modules\\Crm\\Domain', 'App\\Modules\\Crm\\Infrastructure', 'App\\Modules\\Crm\\Http']);
});

arch('Relationships Domain is plain PHP: no framework and no other layer', function () use ($relationships) {
    expect("{$relationships}\\Domain")->not->toUse(['Illuminate', "{$relationships}\\Application", "{$relationships}\\Infrastructure", "{$relationships}\\Http"]);
});

arch('Relationships uses no Eloquent model', function () use ($relationships) {
    expect('Illuminate\\Database\\Eloquent')->not->toBeUsedIn($relationships);
});

arch('Relationships Http does not reach Infrastructure or the database', function () use ($relationships) {
    expect("{$relationships}\\Http")->not->toUse(["{$relationships}\\Infrastructure", 'Illuminate\\Support\\Facades\\DB', 'Illuminate\\Database']);
});

arch('Relationships does not name a Role', function () use ($relationships) {
    expect($relationships)->not->toUse('App\\Modules\\Access\\Application\\Role');
});

arch('only DescribeRelationshipTypes names a Capability case, and only AssignRoles', function () {
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Relationships/Application', 'app/Modules/Relationships/Http']) as $path) {
        $code = SourceScan::code(SourceScan::read($path));
        if (preg_match('/Capability::(?!AssignRoles\b)\w+/', $code) === 1 || (str_contains($code, 'Capability::') && ! str_ends_with($path, 'DescribeRelationshipTypes.php'))) {
            $offenders[] = SourceScan::relative($path);
        }
    }

    expect($offenders)->toBe([])
        ->and(SourceScan::code(SourceScan::read(SourceScan::root().'/app/Modules/Relationships/Application/DescribeRelationshipTypes.php')))->toContain('Capability::AssignRoles');
});

arch('view use cases ask for the definition view capability and manage use cases ask for manage', function () {
    $root = SourceScan::root().'/app/Modules/Relationships/Application';
    expect(SourceScan::code(SourceScan::read($root.'/ReadRelationship.php')))->toContain('viewCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/ListRelationshipDirectory.php')))->toContain('viewCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/ReadRelationshipManagement.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/EstablishRelationship.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/ChangeRelationshipStatus.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/UpdateRelationshipFields.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/DeleteRelationship.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/DecideDefaultRole.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/UpdateRelationshipPersonBasics.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/ListRelationshipCandidates.php')))->toContain('manageCapability');
});

arch('QualifyingRelationships is used only inside Relationships and by Resources\' audience eligibility (F14, A10)', function () {
    expect('App\\Modules\\Relationships\\Application\\QualifyingRelationships')->toOnlyBeUsedIn(['App\\Modules\\Relationships', 'App\\Modules\\Resources\\Application\\AudienceEligibility']);
});

arch('nothing outside Relationships uses Relationships, but the development seeders and Resources\' audience eligibility, which takes only QualifyingRelationships (pinned in ResourcesBoundariesTest)', function () use ($relationships) {
    expect($relationships)->toOnlyBeUsedIn([$relationships, 'Database\\Seeders', 'App\\Modules\\Resources\\Application\\AudienceEligibility']);
});

it('names no other module\'s table', function () {
    $foreign = '/[\'"](?:people|accounts|role_assignments|contact_|discussions|discussion_messages|resource_|membership_grants|security_events)[\'"]/';
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Relationships']) as $path) {
        if (preg_match($foreign, SourceScan::code(SourceScan::read($path))) === 1) {
            $offenders[] = SourceScan::relative($path);
        }
    }

    expect($offenders)->toBe([]);
});

/*
 * `Definitions/*.php` are declarative PHP documents (ADR 0038, F3): no namespace and no class, so Pest's architecture
 * expectations cannot see them and the rules above never reach them. This is the source scan that does (WP4, G6 decision D6).
 * A document may name exactly Access's two public enums, because the format deliberately holds real `Capability` and
 * `ProvisionableRole` cases (F3, F5). Any other namespaced name, written as an import, a group import or inline, is a
 * dependency a data document must not have. Comments are not code (SourceScan::code), so a comment may name anything.
 */
const RELATIONSHIP_DEFINITION_ALLOWED_NAMES = [
    'App\\Modules\\Access\\Application\\Capability',
    'App\\Modules\\Access\\Application\\ProvisionableRole',
];

/**
 * Every namespaced name the code of a definition document uses, normalised to no leading backslash, once each.
 *
 * @return list<string>
 */
function relationshipDefinitionNames(string $source): array
{
    // A backslash inside a string value is data, not a class reference, so string literals are set aside first.
    $code = '';
    foreach (PhpToken::tokenize(SourceScan::code($source)) as $token) {
        if (! $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
            $code .= $token->text;
        }
    }
    preg_match_all('/\\\\?[A-Za-z_]\w*(?:\\\\\w*)+/', $code, $matches);

    return array_values(array_unique(array_map(static fn (string $name): string => ltrim($name, '\\'), $matches[0])));
}

/**
 * The names a definition document uses that it is not allowed to.
 *
 * @return list<string>
 */
function relationshipDefinitionViolations(string $source): array
{
    return array_values(array_diff(relationshipDefinitionNames($source), RELATIONSHIP_DEFINITION_ALLOWED_NAMES));
}

it('catches a forbidden dependency in a definition document and passes the allowed ones (positive control)', function (string $source, array $violations) {
    expect(relationshipDefinitionViolations("<?php\n{$source}\n"))->toBe($violations);
})->with([
    'the two permitted Access enums, imported' => ["use App\\Modules\\Access\\Application\\Capability;\nuse App\\Modules\\Access\\Application\\ProvisionableRole;\nreturn [];", []],
    'an enum case used inline with a leading backslash' => ['return [\\App\\Modules\\Access\\Application\\Capability::ViewVolunteers];', []],
    'a comment that names a forbidden class' => ["/* App\\Modules\\Access\\Application\\Role is never used here. */\n// use App\\Modules\\Relationships\\Domain\\RelationshipType;\nreturn [];", []],
    'a document that uses no class at all' => ["return ['type' => 'x'];", []],
    'a string value that contains a backslash is data, not a class' => ["return ['help' => 'The record\\'s own date', 'path' => 'App\\\\Modules\\\\Access\\\\Application\\\\Role'];", []],
    'another Access Application class' => ["use App\\Modules\\Access\\Application\\Role;\nreturn [];", ['App\\Modules\\Access\\Application\\Role']],
    'a Relationships Application class' => ["use App\\Modules\\Relationships\\Application\\DefinitionSchema;\nreturn [];", ['App\\Modules\\Relationships\\Application\\DefinitionSchema']],
    'a Relationships Domain class' => ["use App\\Modules\\Relationships\\Domain\\RelationshipType;\nreturn [];", ['App\\Modules\\Relationships\\Domain\\RelationshipType']],
    'a Relationships Infrastructure class' => ["use App\\Modules\\Relationships\\Infrastructure\\DirectoryDefinitionSource;\nreturn [];", ['App\\Modules\\Relationships\\Infrastructure\\DirectoryDefinitionSource']],
    'a Relationships Http class' => ["use App\\Modules\\Relationships\\Http\\RelationshipsPresenter;\nreturn [];", ['App\\Modules\\Relationships\\Http\\RelationshipsPresenter']],
    'another module\'s class used inline' => ['return [\\App\\Modules\\Identity\\Application\\FindPeople::class];', ['App\\Modules\\Identity\\Application\\FindPeople']],
    'a framework class' => ["use Illuminate\\Support\\Facades\\DB;\nreturn [];", ['Illuminate\\Support\\Facades\\DB']],
    'a group import hiding a forbidden class beside an allowed one' => ["use App\\Modules\\Access\\Application\\{Capability, Role};\nreturn [];", ['App\\Modules\\Access\\Application\\']],
]);

it('keeps Relationships definition documents to Access\'s two public enums', function () {
    $paths = SourceScan::phpFiles(['app/Modules/Relationships/Definitions']);
    $seen = [];
    $violations = [];
    foreach ($paths as $path) {
        $source = SourceScan::read($path);
        $seen = array_merge($seen, relationshipDefinitionNames($source));
        foreach (relationshipDefinitionViolations($source) as $name) {
            $violations[] = SourceScan::relative($path).' names '.$name;
        }
    }
    sort($seen);

    // A future type's document is covered by the same scan; the two production documents must at least be in it.
    expect(array_map('basename', $paths))->toContain('guardian.php')
        ->and(array_map('basename', $paths))->toContain('volunteer.php')
        ->and(array_values(array_unique($seen)))->toBe(RELATIONSHIP_DEFINITION_ALLOWED_NAMES)
        ->and($violations)->toBe([]);
});
