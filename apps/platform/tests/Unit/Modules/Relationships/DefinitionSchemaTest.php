<?php

declare(strict_types=1);

use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\ProvisionableRole;
use App\Modules\Relationships\Application\DefinitionSource;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Domain\DefinitionSchema;
use App\Modules\Relationships\Domain\InvalidRelationshipDefinition;
use App\Modules\Relationships\Infrastructure\DirectoryDefinitionSource;

/**
 * @return array<string, mixed>
 */
function relationshipDocument(string $type = 'guardian'): array
{
    $document = require base_path("app/Modules/Relationships/Definitions/{$type}.php");
    assert(is_array($document));
    /** @var array<string, mixed> $document */

    return $document;
}

/**
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function relationshipChild(array $document, string $key): array
{
    $child = $document[$key] ?? null;
    assert(is_array($child));
    /** @var array<string, mixed> $child */

    return $child;
}

/**
 * @param  array<string, mixed>  $document
 */
function relationshipRefused(array $document, string $fragment): void
{
    expect(fn () => DefinitionSchema::check($document, is_string($document['type'] ?? null) ? $document['type'] : 'untyped'))
        ->toThrow(InvalidRelationshipDefinition::class, $fragment);
}

/** @param  list<array<string, mixed>>  $documents */
function relationshipCatalogFrom(array $documents): RelationshipCatalog
{
    return RelationshipCatalog::load([new class($documents) implements DefinitionSource
    {
        /** @param  list<array<string, mixed>>  $documents */
        public function __construct(private array $documents) {}

        public function documents(): array
        {
            return $this->documents;
        }
    }]);
}

it('loads the production Guardian and Volunteer documents', function () {
    $catalog = RelationshipCatalog::load([new DirectoryDefinitionSource]);
    $types = array_map(fn ($definition) => $definition->type->key, $catalog->all());

    $guardian = $catalog->type('guardian');
    $volunteer = $catalog->type('volunteer');
    assert($guardian !== null && $volunteer !== null);

    expect($types)->toBe(['guardian', 'volunteer'])
        ->and($catalog->definition($guardian)->defaultRole)->toBe(ProvisionableRole::GuardianInitiate)
        ->and($catalog->definition($volunteer)->defaultRole)->toBeNull()
        ->and($catalog->definition($guardian)->verification)->toBe(['intake', 'status', 'default_role', 'delete'])
        ->and($catalog->definition($volunteer)->verification)->toBe(['delete']);
});

/**
 * The production document `$base` names, or the Guardian's with one more field, a `choice`, so the choice rules have something valid
 * to break.
 *
 * @return array<string, mixed>
 */
function relationshipBase(string $base): array
{
    if ($base !== 'guardian+choice') {
        return relationshipDocument($base);
    }

    return relationshipSet(relationshipDocument('guardian'), ['fields', 'kind'], [
        'value_type' => 'choice', 'options' => ['a', 'b'], 'required' => false, 'visibility' => 'view', 'label' => 'Kind', 'order' => 3,
    ]);
}

/**
 * The document with the value at `$path` replaced.
 *
 * @param  array<string, mixed>  $document
 * @param  list<string>  $path
 * @return array<string, mixed>
 */
function relationshipSet(array $document, array $path, mixed $value): array
{
    $key = array_shift($path);
    assert($key !== null);
    if ($path === []) {
        $document[$key] = $value;

        return $document;
    }
    $child = $document[$key] ?? null;
    assert(is_array($child));
    /** @var array<string, mixed> $child */
    $document[$key] = relationshipSet($child, $path, $value);

    return $document;
}

/**
 * The document without the key at `$path`.
 *
 * @param  array<string, mixed>  $document
 * @param  list<string>  $path
 * @return array<string, mixed>
 */
function relationshipWithout(array $document, array $path): array
{
    $key = array_shift($path);
    assert($key !== null);
    if ($path === []) {
        unset($document[$key]);

        return $document;
    }
    $child = $document[$key] ?? null;
    assert(is_array($child));
    /** @var array<string, mixed> $child */
    $document[$key] = relationshipWithout($child, $path);

    return $document;
}

/**
 * The document with one state renamed everywhere it is named, so the new key is the only thing wrong with it.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function relationshipRenamedState(array $document, string $from, string $to): array
{
    $rename = fn (mixed $key): string => $key === $from ? $to : (is_string($key) ? $key : '');
    $states = relationshipChild($document, 'states');
    $transitions = relationshipChild($document, 'transitions');
    $initial = $document['initial_states'] ?? null;
    assert(is_array($initial));

    $renamedStates = [];
    foreach ($states as $key => $state) {
        $renamedStates[$rename($key)] = $state;
    }
    $renamedTransitions = [];
    foreach ($transitions as $key => $targets) {
        assert(is_array($targets));
        $renamedTransitions[$rename($key)] = array_map($rename, $targets);
    }
    $document['states'] = $renamedStates;
    $document['transitions'] = $renamedTransitions;
    $document['initial_states'] = array_map($rename, $initial);

    return $document;
}

/**
 * The document with one edit applied.
 *
 * @param  array<string, mixed>  $document
 * @param  array{string, list<string>, mixed}  $edit
 * @return array<string, mixed>
 */
function relationshipEdited(array $document, array $edit): array
{
    [$kind, $path, $value] = $edit;

    return match ($kind) {
        'set' => relationshipSet($document, $path, $value),
        'unset' => relationshipWithout($document, $path),
        'rename_state' => relationshipRenamedState($document, $path[0] ?? '', $path[1] ?? ''),
        'rename_field' => relationshipRenamedField($document, $path[0] ?? '', $path[1] ?? ''),
        default => throw new LogicException("Unknown edit {$kind}."),
    };
}

/**
 * The document with one field key renamed.
 *
 * @param  array<string, mixed>  $document
 * @return array<string, mixed>
 */
function relationshipRenamedField(array $document, string $from, string $to): array
{
    $renamed = [];
    foreach (relationshipChild($document, 'fields') as $key => $field) {
        $renamed[$key === $from ? $to : $key] = $field;
    }
    $document['fields'] = $renamed;

    return $document;
}

/**
 * ADR 0038, F5: every rule `DefinitionSchema` enforces, one case each. A case names the production document it starts from (`guardian`,
 * `volunteer`, or `guardian+choice`), breaks exactly one thing in it, and says which rule must refuse it. The test first checks that the
 * unbroken document loads, so a case can only fail through the thing it breaks, and then that the refusal names the rule and the document.
 *
 * Two kinds of case break a second rule whatever is done, and are noted where they are: an empty `states` map leaves `initial_states`
 * and `transitions` naming nothing (the fragment pins the refusal to the states rule, which is checked first), and so does every
 * state rename. F1 (a field key is unique within a definition) has no case: a PHP array cannot hold two entries under one key, so a
 * document cannot say it. The proof is that a definition is a PHP array and the last of two literal duplicate keys wins before the
 * schema sees anything; the schema's own key rule (`FIELD_SHAPE`) is the case "a field key is not a lowercase identifier".
 *
 * An edit is `['set', path, value]`, `['unset', path, null]`, `['rename_state', [from, to], null]` or `['rename_field', [from, to], null]`.
 *
 * @return array<string, array{string, array{string, list<string>, mixed}, string}>
 */
function relationshipRefusals(): array
{
    return [
        // Types
        'the type key is not a lowercase identifier' => ['guardian', ['set', ['type'], 'Guardian'], 'type must match'],
        'the slug is not a lowercase identifier' => ['guardian', ['set', ['slug'], '1bad'], 'slug must match'],
        'the version is not an integer' => ['guardian', ['set', ['version'], '1'], 'version must be an integer of at least 1'],
        'the version is below one' => ['guardian', ['set', ['version'], 0], 'version must be an integer of at least 1'],

        // Unknown keys are refused, never ignored
        'an unknown top-level key' => ['guardian', ['set', ['operator'], true], 'unknown key "operator"'],
        'an unknown labels key' => ['guardian', ['set', ['labels', 'tagline'], 'x'], 'unknown labels key "tagline"'],
        'an unknown state key' => ['guardian', ['set', ['states', 'active', 'colour'], 'red'], 'unknown state key on "active"'],
        'an unknown field key' => ['guardian', ['set', ['fields', 'stewardship', 'pattern'], '.*'], 'unknown field key on "stewardship"'],
        'an unknown capabilities key' => ['guardian', ['set', ['capabilities', 'audit'], Capability::ViewGuardians], 'unknown capabilities key "audit"'],
        'an unknown presentation key' => ['guardian', ['set', ['presentation', 'depth'], 1], 'unknown presentation key "depth"'],

        // Labels
        'labels are not a map' => ['guardian', ['set', ['labels'], 'Guardian'], 'labels must be a map'],
        'a label is empty' => ['guardian', ['set', ['labels', 'singular'], ''], 'labels.singular must be a non-empty string'],
        'a label is missing' => ['guardian', ['unset', ['labels', 'plural'], null], 'labels.plural must be a non-empty string'],
        'the help label is empty' => ['guardian', ['set', ['labels', 'help'], ''], 'labels.help must be a non-empty string when present'],

        // Lifecycle: states
        'there is no state (the initial states and transitions then name nothing, and are checked later)' => ['guardian', ['set', ['states'], []], 'at least one state is required'],
        'a state key is not a lowercase identifier (renamed everywhere it is named)' => ['guardian', ['rename_state', ['active', 'Active'], null], 'a state key must match'],
        'a state is not a map' => ['guardian', ['set', ['states', 'active'], 'Active'], 'state "active" must be a map'],
        'a state label is empty' => ['guardian', ['set', ['states', 'active', 'label'], ''], 'state "active" label must be a non-empty string'],
        'a state description is missing' => ['guardian', ['unset', ['states', 'active', 'description'], null], 'state "active" description must be a non-empty string'],
        'a state tone is outside the closed set' => ['guardian', ['set', ['states', 'active', 'tone'], 'urgent'], 'tone must be one of'],
        'a state qualifies is not a boolean' => ['guardian', ['set', ['states', 'active', 'qualifies'], 'yes'], 'qualifies must be a boolean'],

        // Lifecycle: initial states
        'the initial states are empty' => ['guardian', ['set', ['initial_states'], []], 'initial_states must be a non-empty list'],
        'the initial states are a map, not a list' => ['guardian', ['set', ['initial_states'], ['first' => 'active']], 'initial_states must be a non-empty list'],
        'an initial state is not declared' => ['guardian', ['set', ['initial_states'], ['former']], 'initial_states must name declared states'],
        'an initial state is repeated' => ['guardian', ['set', ['initial_states'], ['active', 'active']], 'initial state "active" is repeated'],

        // Lifecycle: transitions
        'transitions are not a map' => ['guardian', ['set', ['transitions'], 'none'], 'transitions must be a map'],
        'a state has no transitions entry' => ['guardian', ['unset', ['transitions', 'inactive'], null], 'transitions must name every declared state exactly once'],
        'transitions name a state that is not declared' => ['guardian', ['set', ['transitions', 'former'], ['active']], 'transitions must name every declared state exactly once'],
        'the targets of a state are not a list' => ['guardian', ['set', ['transitions', 'active'], 'inactive'], 'transitions from "active" must be a list'],
        'a transition targets an undeclared state' => ['guardian', ['set', ['transitions', 'active'], ['former']], 'transition "active" targets an undeclared state'],
        'a transition targets its own source' => ['guardian', ['set', ['transitions', 'active'], ['active']], 'transition "active" targets itself'],
        'a transition target is repeated' => ['guardian', ['set', ['transitions', 'active'], ['inactive', 'inactive']], 'transition "active" repeats "inactive"'],

        // Fields
        'fields are not a map' => ['guardian', ['set', ['fields'], 'none'], 'fields must be a map'],
        'a field key is not a lowercase identifier (F1: a duplicate key cannot be written in a PHP array)' => ['guardian', ['rename_field', ['stewardship', 'Stewardship'], null], 'a field key must match'],
        'a field is not a map' => ['guardian', ['set', ['fields', 'stewardship'], 'text'], 'field "stewardship" must be a map'],
        'a field value type is outside the closed set' => ['guardian', ['set', ['fields', 'stewardship', 'value_type'], 'number'], 'field "stewardship" value_type must be one of'],
        'a field required is not a boolean' => ['guardian', ['set', ['fields', 'stewardship', 'required'], 'no'], 'field "stewardship" required must be a boolean'],
        'a field visibility is neither view nor manage' => ['guardian', ['set', ['fields', 'stewardship', 'visibility'], 'public'], 'field "stewardship" visibility must be view or manage'],
        'a field label is empty' => ['guardian', ['set', ['fields', 'stewardship', 'label'], ''], 'field "stewardship" label must be a non-empty string'],
        'a field order is not an integer' => ['guardian', ['set', ['fields', 'stewardship', 'order'], '2'], 'field "stewardship" order must be an integer'],
        'a field help is empty' => ['guardian', ['set', ['fields', 'stewardship', 'help'], ''], 'field "stewardship" help must be a non-empty string when present'],
        'a field group is empty' => ['guardian', ['set', ['fields', 'stewardship', 'group'], ''], 'field "stewardship" group must be a non-empty string when present'],
        'a text field has no max_length' => ['guardian', ['unset', ['fields', 'stewardship', 'max_length'], null], 'field "stewardship" max_length must be an integer from 1 to 255'],
        'a text field max_length is above its ceiling' => ['guardian', ['set', ['fields', 'stewardship', 'max_length'], 256], 'max_length must be an integer from 1 to 255'],
        'a text field max_length is below one' => ['guardian', ['set', ['fields', 'stewardship', 'max_length'], 0], 'max_length must be an integer from 1 to 255'],
        'a text field max_length is not an integer' => ['guardian', ['set', ['fields', 'stewardship', 'max_length'], '200'], 'max_length must be an integer from 1 to 255'],
        'a long text field max_length is above its ceiling' => ['volunteer', ['set', ['fields', 'interests', 'max_length'], 2001], 'max_length must be an integer from 1 to 2000'],
        'a date field takes no max_length' => ['guardian', ['set', ['fields', 'recognized_on', 'max_length'], 10], 'field "recognized_on" does not take max_length'],
        'a text field takes no not_after' => ['guardian', ['set', ['fields', 'stewardship', 'not_after'], 'today'], 'field "stewardship" does not take not_after'],
        'a date field not_after is not today' => ['guardian', ['set', ['fields', 'recognized_on', 'not_after'], 'tomorrow'], 'field "recognized_on" not_after must be today'],
        'a text field takes no options' => ['guardian', ['set', ['fields', 'stewardship', 'options'], ['a']], 'field "stewardship" does not take options'],
        'a choice field has no options' => ['guardian+choice', ['unset', ['fields', 'kind', 'options'], null], 'field "kind" options must be a non-empty list'],
        'a choice field has an empty list of options' => ['guardian+choice', ['set', ['fields', 'kind', 'options'], []], 'field "kind" options must be a non-empty list'],
        'the options of a choice field are a map, not a list' => ['guardian+choice', ['set', ['fields', 'kind', 'options'], ['x' => 'a']], 'field "kind" options must be a non-empty list'],
        'a choice option is repeated' => ['guardian+choice', ['set', ['fields', 'kind', 'options'], ['a', 'a']], 'field "kind" options must be unique and non-empty'],
        'a choice option is empty' => ['guardian+choice', ['set', ['fields', 'kind', 'options'], ['a', '']], 'field "kind" options must be unique and non-empty'],

        // Capabilities
        'capabilities are not a map' => ['guardian', ['set', ['capabilities'], 'guardians.view'], 'capabilities must be a map'],
        'the view capability is outside the relationship family' => ['guardian', ['set', ['capabilities', 'view'], Capability::ConsoleAccess], 'capabilities.view must be a relationship capability'],
        'the manage capability is outside the relationship family' => ['guardian', ['set', ['capabilities', 'manage'], Capability::AssignRoles], 'capabilities.manage must be a relationship capability'],
        'a capability is named by its string, not as a Capability' => ['guardian', ['set', ['capabilities', 'manage'], 'guardians.manage'], 'capabilities.manage must be a relationship capability'],
        'the manage capability is missing' => ['guardian', ['unset', ['capabilities', 'manage'], null], 'capabilities.manage must be a relationship capability'],
        'view and manage are the same capability' => ['guardian', ['set', ['capabilities', 'manage'], Capability::ViewGuardians], 'view and manage capabilities must differ'],

        // Features, verification (a closed vocabulary, no repeats)
        'features are not a list' => ['guardian', ['set', ['features'], 'directory'], 'features must be a list'],
        'a feature is outside the closed set' => ['guardian', ['set', ['features'], ['directory', 'billing']], 'unknown feature'],
        'a feature is repeated' => ['guardian', ['set', ['features'], ['directory', 'directory']], 'repeated feature "directory"'],
        'verification is not a list' => ['guardian', ['set', ['verification'], 'delete'], 'verification operations must be a list'],
        'a verified operation is outside the closed set' => ['guardian', ['set', ['verification'], ['intake', 'status', 'default_role', 'delete', 'export']], 'unknown verification operation'],
        'a verified operation is repeated' => ['guardian', ['set', ['verification'], ['intake', 'status', 'default_role', 'delete', 'delete']], 'repeated verification operation "delete"'],

        // Deletion and its verification
        'deletion is not a boolean' => ['guardian', ['set', ['deletion'], 'yes'], 'deletion must be a boolean'],
        'deletion is allowed and delete is not a verified operation (no default role, so nothing else is wrong)' => ['volunteer', ['set', ['verification'], []], 'delete must be a verified operation when deletion is allowed'],
        'deletion is not allowed and delete is a verified operation' => ['volunteer', ['set', ['deletion'], false], 'delete is verified but deletion is not allowed'],

        // Default role (A10)
        'the default role is missing' => ['guardian', ['unset', ['default_role'], null], 'default_role must be null or a provisionable role'],
        'the default role is a role key, not a ProvisionableRole' => ['guardian', ['set', ['default_role'], 'guardian-initiate'], 'default_role must be null or a provisionable role'],
        'the default role is a role key on a type with none' => ['volunteer', ['set', ['default_role'], 'guardian-initiate'], 'default_role must be null or a provisionable role'],
        'a default role without verified intake' => ['guardian', ['set', ['verification'], ['status', 'default_role', 'delete']], 'a default role requires verified intake, status and default_role'],
        'a default role without verified status' => ['guardian', ['set', ['verification'], ['intake', 'default_role', 'delete']], 'a default role requires verified intake, status and default_role'],
        'a default role without verified default_role' => ['guardian', ['set', ['verification'], ['intake', 'status', 'delete']], 'a default role requires verified intake, status and default_role'],

        // Presentation and components
        'presentation is not a map' => ['guardian', ['set', ['presentation'], 10], 'presentation must be a map'],
        'the presentation position is not an integer' => ['guardian', ['set', ['presentation', 'position'], '10'], 'presentation.position must be an integer'],
        'components are not a list' => ['guardian', ['set', ['components'], 'panel'], 'components must be a list'],
        'a component is not registered' => ['guardian', ['set', ['components'], ['guardian-panel']], 'unknown component'],
    ];
}

it('refuses a definition that breaks one rule, naming the rule and the document', function (string $base, array $edit, string $fragment) {
    $valid = relationshipBase($base);
    $type = $valid['type'];
    assert(is_string($type));
    // The unbroken document loads, so the refusal below is the one thing that was broken.
    expect(DefinitionSchema::check($valid, $type)->type->key)->toBe($type);

    /** @var array{string, list<string>, mixed} $edit */
    $broken = relationshipEdited($valid, $edit);
    $name = is_string($broken['type'] ?? null) ? $broken['type'] : 'untyped';

    try {
        DefinitionSchema::check($broken, $name);
        $thrown = null;
    } catch (InvalidRelationshipDefinition $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(InvalidRelationshipDefinition::class)
        ->and($thrown?->document)->toBe($name)
        ->and($thrown?->getMessage())->toContain("Relationship definition \"{$name}\" is invalid")
        ->and($thrown?->getMessage())->toContain($fragment);
})->with(relationshipRefusals());

/**
 * What loading these documents as one catalog refuses with: the document named and the rule.
 *
 * @param  list<array<string, mixed>>  $documents
 */
function relationshipCatalogRefusal(array $documents): InvalidRelationshipDefinition
{
    try {
        relationshipCatalogFrom($documents);
    } catch (InvalidRelationshipDefinition $exception) {
        return $exception;
    }

    throw new LogicException('The catalog loaded these documents; it should have refused them.');
}

it('refuses two documents that share a type, naming the second', function () {
    $volunteer = relationshipDocument('volunteer');
    $again = $volunteer;
    $again['slug'] = 'volunteers_again';
    $again['capabilities'] = ['view' => Capability::ViewGuardians, 'manage' => Capability::ManageGuardians];

    $refused = relationshipCatalogRefusal([$volunteer, $again]);

    expect($refused->document)->toBe('volunteer')
        ->and($refused->rule)->toBe('type is already defined')
        ->and($refused->getMessage())->toContain('Relationship definition "volunteer" is invalid: type is already defined');
});

it('refuses two documents that share a slug, naming the second', function () {
    $guardian = relationshipDocument();
    $other = relationshipDocument('volunteer');
    $other['type'] = 'other';
    $other['slug'] = 'guardians';

    $refused = relationshipCatalogRefusal([$guardian, $other]);

    expect($refused->document)->toBe('other')
        ->and($refused->rule)->toBe('slug is already used');
});

it('refuses two documents that share a capability across types, whichever half, naming the second', function (Capability $shared, string $half) {
    $guardian = relationshipDocument();
    $other = relationshipDocument('volunteer');
    $other['type'] = 'apprentice';
    $other['slug'] = 'apprentices';
    $other['capabilities'] = $half === 'view'
        ? ['view' => $shared, 'manage' => Capability::ManageVolunteers]
        : ['view' => Capability::ViewVolunteers, 'manage' => $shared];

    $refused = relationshipCatalogRefusal([$guardian, $other]);

    expect($refused->document)->toBe('apprentice')
        ->and($refused->rule)->toBe("capability {$shared->value} already serves guardian");
})->with([
    'the view half of one type serves as the view half of another' => [Capability::ViewGuardians, 'view'],
    'the manage half of one type serves as the manage half of another' => [Capability::ManageGuardians, 'manage'],
    'the view half of one type serves as the manage half of another' => [Capability::ViewGuardians, 'manage'],
    'the manage half of one type serves as the view half of another' => [Capability::ManageGuardians, 'view'],
]);
