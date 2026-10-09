<?php

declare(strict_types=1);

use App\Modules\Access\Application\Capability;
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

it('loads the production Guardian and Volunteer documents', function () {
    $catalog = RelationshipCatalog::load([new DirectoryDefinitionSource]);
    $types = array_map(fn ($definition) => $definition->type->key, $catalog->all());

    $guardian = $catalog->type('guardian');
    $volunteer = $catalog->type('volunteer');
    assert($guardian !== null && $volunteer !== null);

    expect($types)->toBe(['guardian', 'volunteer'])
        ->and($catalog->definition($guardian)->defaultRole)->toBeNull()
        ->and($catalog->definition($volunteer)->defaultRole)->toBeNull()
        ->and($catalog->definition($guardian)->verification)->toBe(['intake', 'status', 'delete'])
        ->and($catalog->definition($volunteer)->verification)->toBe(['delete']);
});

it('refuses a type key, a slug, an unknown key and a missing default role', function () {
    $type = relationshipDocument();
    $type['type'] = 'Guardian';
    relationshipRefused($type, 'type must match');

    $slug = relationshipDocument();
    $slug['slug'] = '1bad';
    relationshipRefused($slug, 'slug must match');

    $unknown = relationshipDocument();
    $unknown['operator'] = true;
    relationshipRefused($unknown, 'unknown key');

    $missing = relationshipDocument();
    unset($missing['default_role']);
    relationshipRefused($missing, 'default_role must be null or a provisionable role');

    $named = relationshipDocument();
    $named['default_role'] = 'guardian';
    relationshipRefused($named, 'default_role must be null or a provisionable role');
});

it('refuses a lifecycle that is not a closed map of declared states', function () {
    $empty = relationshipDocument();
    $empty['states'] = [];
    relationshipRefused($empty, 'at least one state');

    $initial = relationshipDocument();
    $initial['initial_states'] = [];
    relationshipRefused($initial, 'initial_states must be a non-empty list');

    $undeclared = relationshipDocument();
    $undeclared['initial_states'] = ['former'];
    relationshipRefused($undeclared, 'initial_states must name declared states');

    $missing = relationshipDocument();
    $missingTransitions = relationshipChild($missing, 'transitions');
    unset($missingTransitions['inactive']);
    $missing['transitions'] = $missingTransitions;
    relationshipRefused($missing, 'transitions must name every declared state exactly once');

    $self = relationshipDocument();
    $selfTransitions = relationshipChild($self, 'transitions');
    $selfTransitions['active'] = ['active'];
    $self['transitions'] = $selfTransitions;
    relationshipRefused($self, 'targets itself');

    $repeat = relationshipDocument();
    $repeatTransitions = relationshipChild($repeat, 'transitions');
    $repeatTransitions['active'] = ['inactive', 'inactive'];
    $repeat['transitions'] = $repeatTransitions;
    relationshipRefused($repeat, 'repeats');
});

it('refuses a capability outside the relationship family, a shared half, an unknown feature and a broken deletion contract', function () {
    $console = relationshipDocument();
    $consoleCapabilities = relationshipChild($console, 'capabilities');
    $consoleCapabilities['view'] = Capability::ConsoleAccess;
    $console['capabilities'] = $consoleCapabilities;
    relationshipRefused($console, 'must be a relationship capability');

    $same = relationshipDocument();
    $sameCapabilities = relationshipChild($same, 'capabilities');
    $sameCapabilities['manage'] = Capability::ViewGuardians;
    $same['capabilities'] = $sameCapabilities;
    relationshipRefused($same, 'must differ');

    $feature = relationshipDocument();
    $feature['features'] = ['billing'];
    relationshipRefused($feature, 'unknown feature');

    $deletion = relationshipDocument();
    $deletion['verification'] = ['intake', 'status'];
    relationshipRefused($deletion, 'delete must be a verified operation');

    $verified = relationshipDocument('volunteer');
    $verified['deletion'] = false;
    relationshipRefused($verified, 'delete is verified but deletion is not allowed');

    $component = relationshipDocument();
    $component['components'] = ['guardian-panel'];
    relationshipRefused($component, 'unknown component');
});

it('refuses a field the value type does not take, and a tone outside the closed set', function () {
    $tone = relationshipDocument();
    $toneStates = relationshipChild($tone, 'states');
    $toneActive = $toneStates['active'] ?? null;
    assert(is_array($toneActive));
    $toneActive['tone'] = 'urgent';
    $toneStates['active'] = $toneActive;
    $tone['states'] = $toneStates;
    relationshipRefused($tone, 'tone must be one of');

    $length = relationshipDocument();
    $lengthFields = relationshipChild($length, 'fields');
    $recognizedOn = $lengthFields['recognized_on'] ?? null;
    assert(is_array($recognizedOn));
    $recognizedOn['max_length'] = 10;
    $lengthFields['recognized_on'] = $recognizedOn;
    $length['fields'] = $lengthFields;
    relationshipRefused($length, 'does not take max_length');

    $options = relationshipDocument();
    $optionFields = relationshipChild($options, 'fields');
    $stewardship = $optionFields['stewardship'] ?? null;
    assert(is_array($stewardship));
    $stewardship['options'] = ['a'];
    $optionFields['stewardship'] = $stewardship;
    $options['fields'] = $optionFields;
    relationshipRefused($options, 'does not take options');
});

it('refuses two documents that share a type, a slug or a capability', function () {
    $guardian = relationshipDocument();
    $volunteer = relationshipDocument('volunteer');
    $again = $volunteer;
    $again['type'] = 'guardian';

    expect(fn () => RelationshipCatalog::load([new class($guardian, $again) implements DefinitionSource
    {
        /** @param  array<string, mixed>  $first
         * @param  array<string, mixed>  $second */
        public function __construct(private array $first, private array $second) {}

        public function documents(): array
        {
            return [$this->first, $this->second];
        }
    }]))->toThrow(InvalidRelationshipDefinition::class, 'already defined');

    $slug = $volunteer;
    $slug['slug'] = 'guardians';
    $slug['type'] = 'other';
    $slug['capabilities'] = ['view' => Capability::ViewVolunteers, 'manage' => Capability::ManageVolunteers];
    expect(fn () => RelationshipCatalog::load([new class($guardian, $slug) implements DefinitionSource
    {
        /** @param  array<string, mixed>  $first
         * @param  array<string, mixed>  $second */
        public function __construct(private array $first, private array $second) {}

        public function documents(): array
        {
            return [$this->first, $this->second];
        }
    }]))->toThrow(InvalidRelationshipDefinition::class, 'slug is already used');
});
