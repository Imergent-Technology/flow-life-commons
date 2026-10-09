<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use App\Modules\Access\Application\Capability;

/**
 * Validates one relationship definition document (ADR 0038, F5). Definitions are data:
 * strings, numbers, booleans, lists and maps, plus Access's public `Capability` cases.
 * An unknown key is refused, never ignored. Nothing in a document is executable.
 *
 * `default_role` stays null. Access has `ProvisionableRole` (WP2A), but this schema's
 * allowlist stays empty until WP2B wires Guardian Initiate. Any other value is refused:
 * fail closed, never a free-form role key.
 */
final class DefinitionSchema
{
    public const string KEY_SHAPE = '/^[a-z][a-z0-9_]{1,31}$/D';

    public const string STATE_SHAPE = '/^[a-z][a-z0-9_]{0,15}$/D';

    public const string FIELD_SHAPE = '/^[a-z][a-z0-9_]{0,63}$/D';

    /** @var list<string> */
    public const array VALUE_TYPES = ['text', 'long_text', 'date', 'boolean', 'choice'];

    /** @var list<string> */
    public const array TONES = ['positive', 'caution', 'neutral'];

    /** @var list<string> */
    public const array FEATURES = [
        'directory', 'people.lookup', 'people.create_person', 'people.read_basics', 'people.edit_basics',
    ];

    /** @var list<string> */
    public const array OPERATIONS = ['intake', 'status', 'default_role', 'delete'];

    /**
     * Relationship-family capabilities only. A type may not name `console.access`,
     * `access.roles.assign`, `identity.*`, `crm.*` or any other area (F5).
     *
     * @var list<string>
     */
    private const array RELATIONSHIP_CAPABILITIES = [
        'guardians.view', 'guardians.manage', 'volunteers.view', 'volunteers.manage',
    ];

    /** @var list<string> Console component keys registered for definitions. G10 registers none. */
    public const array COMPONENTS = [];

    /** @var list<string> */
    private const array TOP_LEVEL = [
        'type', 'slug', 'version', 'labels', 'states', 'initial_states', 'transitions', 'fields',
        'capabilities', 'features', 'verification', 'deletion', 'default_role', 'presentation', 'components',
    ];

    /**
     * @param  array<string, mixed>  $document
     */
    public static function check(array $document, string $name): RelationshipDefinition
    {
        self::onlyKeys($document, self::TOP_LEVEL, $name, 'unknown key');

        $typeKey = self::shaped($document['type'] ?? null, self::KEY_SHAPE, $name, 'type must match '.self::KEY_SHAPE);
        $slug = self::shaped($document['slug'] ?? null, self::KEY_SHAPE, $name, 'slug must match '.self::KEY_SHAPE);
        $version = self::intAtLeast($document['version'] ?? null, 1, $name, 'version must be an integer of at least 1');
        $labels = self::labels($document['labels'] ?? null, $name);
        $states = self::states($document['states'] ?? null, $name);
        $initial = self::initialStates($document['initial_states'] ?? null, $states, $name);
        $transitions = self::transitions($document['transitions'] ?? null, $states, $name);
        $fields = self::fields($document['fields'] ?? null, $name);
        [$view, $manage] = self::capabilities($document['capabilities'] ?? null, $name);
        $features = self::vocabulary($document['features'] ?? null, self::FEATURES, $name, 'feature');
        $verification = self::vocabulary($document['verification'] ?? null, self::OPERATIONS, $name, 'verification operation');
        $deletion = self::bool($document['deletion'] ?? null, $name, 'deletion must be a boolean');
        self::defaultRole(array_key_exists('default_role', $document) ? $document['default_role'] : 'missing', $name);
        $position = self::presentation($document['presentation'] ?? null, $name);
        $components = self::components($document['components'] ?? [], $name);

        if ($deletion && ! in_array('delete', $verification, true)) {
            throw new InvalidRelationshipDefinition($name, 'delete must be a verified operation when deletion is allowed');
        }
        if (! $deletion && in_array('delete', $verification, true)) {
            throw new InvalidRelationshipDefinition($name, 'delete is verified but deletion is not allowed');
        }

        return new RelationshipDefinition(
            RelationshipType::issue($typeKey), $slug, $version, $labels, $states, $initial, $transitions, $fields,
            $view, $manage, $features, $verification, $deletion, $position, $components, null,
        );
    }

    /**
     * @param  array<mixed, mixed>  $document
     * @param  list<string>  $allowed
     */
    private static function onlyKeys(array $document, array $allowed, string $name, string $rule): void
    {
        foreach (array_keys($document) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new InvalidRelationshipDefinition($name, $rule.' "'.(is_string($key) ? $key : 'non-string').'"');
            }
        }
    }

    private static function shaped(mixed $value, string $pattern, string $name, string $rule): string
    {
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidRelationshipDefinition($name, $rule);
        }

        return $value;
    }

    private static function intAtLeast(mixed $value, int $least, string $name, string $rule): int
    {
        if (! is_int($value) || $value < $least) {
            throw new InvalidRelationshipDefinition($name, $rule);
        }

        return $value;
    }

    private static function bool(mixed $value, string $name, string $rule): bool
    {
        if (! is_bool($value)) {
            throw new InvalidRelationshipDefinition($name, $rule);
        }

        return $value;
    }

    /**
     * @return array{singular: string, plural: string, description: string, help: ?string}
     */
    private static function labels(mixed $labels, string $name): array
    {
        if (! is_array($labels)) {
            throw new InvalidRelationshipDefinition($name, 'labels must be a map');
        }
        self::onlyKeys($labels, ['singular', 'plural', 'description', 'help'], $name, 'unknown labels key');
        foreach (['singular', 'plural', 'description'] as $key) {
            if (! is_string($labels[$key] ?? null) || $labels[$key] === '') {
                throw new InvalidRelationshipDefinition($name, "labels.{$key} must be a non-empty string");
            }
        }
        $help = $labels['help'] ?? null;
        if ($help !== null && (! is_string($help) || $help === '')) {
            throw new InvalidRelationshipDefinition($name, 'labels.help must be a non-empty string when present');
        }

        return [
            'singular' => $labels['singular'], 'plural' => $labels['plural'],
            'description' => $labels['description'], 'help' => $help,
        ];
    }

    /**
     * @return array<string, RelationshipState>
     */
    private static function states(mixed $states, string $name): array
    {
        if (! is_array($states) || $states === []) {
            throw new InvalidRelationshipDefinition($name, 'at least one state is required');
        }
        $built = [];
        foreach ($states as $key => $state) {
            if (! is_string($key) || preg_match(self::STATE_SHAPE, $key) !== 1) {
                throw new InvalidRelationshipDefinition($name, 'a state key must match '.self::STATE_SHAPE);
            }
            if (! is_array($state)) {
                throw new InvalidRelationshipDefinition($name, "state \"{$key}\" must be a map");
            }
            self::onlyKeys($state, ['label', 'description', 'tone', 'qualifies'], $name, "unknown state key on \"{$key}\"");
            foreach (['label', 'description'] as $text) {
                if (! is_string($state[$text] ?? null) || $state[$text] === '') {
                    throw new InvalidRelationshipDefinition($name, "state \"{$key}\" {$text} must be a non-empty string");
                }
            }
            if (! is_string($state['tone'] ?? null) || ! in_array($state['tone'], self::TONES, true)) {
                throw new InvalidRelationshipDefinition($name, "state \"{$key}\" tone must be one of ".implode(', ', self::TONES));
            }
            if (! is_bool($state['qualifies'] ?? null)) {
                throw new InvalidRelationshipDefinition($name, "state \"{$key}\" qualifies must be a boolean");
            }
            $built[$key] = new RelationshipState($key, $state['label'], $state['description'], $state['tone'], $state['qualifies']);
        }

        return $built;
    }

    /**
     * @param  array<string, RelationshipState>  $states
     * @return list<string>
     */
    private static function initialStates(mixed $initial, array $states, string $name): array
    {
        if (! is_array($initial) || ! array_is_list($initial) || $initial === []) {
            throw new InvalidRelationshipDefinition($name, 'initial_states must be a non-empty list of declared states');
        }
        $keys = [];
        foreach ($initial as $state) {
            if (! is_string($state) || ! array_key_exists($state, $states)) {
                throw new InvalidRelationshipDefinition($name, 'initial_states must name declared states');
            }
            if (in_array($state, $keys, true)) {
                throw new InvalidRelationshipDefinition($name, "initial state \"{$state}\" is repeated");
            }
            $keys[] = $state;
        }

        return $keys;
    }

    /**
     * @param  array<string, RelationshipState>  $states
     * @return array<string, list<string>>
     */
    private static function transitions(mixed $transitions, array $states, string $name): array
    {
        if (! is_array($transitions)) {
            throw new InvalidRelationshipDefinition($name, 'transitions must be a map');
        }
        $declared = array_keys($states);
        sort($declared);
        $sources = array_keys($transitions);
        $sourceKeys = array_map(strval(...), array_filter($sources, is_string(...)));
        sort($sourceKeys);
        if ($sourceKeys !== $declared) {
            throw new InvalidRelationshipDefinition($name, 'transitions must name every declared state exactly once');
        }
        $built = [];
        foreach ($declared as $from) {
            $targets = $transitions[$from];
            if (! is_array($targets) || ! array_is_list($targets)) {
                throw new InvalidRelationshipDefinition($name, "transitions from \"{$from}\" must be a list");
            }
            $to = [];
            foreach ($targets as $target) {
                if (! is_string($target) || ! array_key_exists($target, $states)) {
                    throw new InvalidRelationshipDefinition($name, "transition \"{$from}\" targets an undeclared state");
                }
                if ($target === $from) {
                    throw new InvalidRelationshipDefinition($name, "transition \"{$from}\" targets itself");
                }
                if (in_array($target, $to, true)) {
                    throw new InvalidRelationshipDefinition($name, "transition \"{$from}\" repeats \"{$target}\"");
                }
                $to[] = $target;
            }
            $built[$from] = $to;
        }

        return $built;
    }

    /**
     * @return array<string, RelationshipField>
     */
    private static function fields(mixed $fields, string $name): array
    {
        if (! is_array($fields)) {
            throw new InvalidRelationshipDefinition($name, 'fields must be a map');
        }
        $built = [];
        foreach ($fields as $key => $field) {
            if (! is_string($key) || preg_match(self::FIELD_SHAPE, $key) !== 1) {
                throw new InvalidRelationshipDefinition($name, 'a field key must match '.self::FIELD_SHAPE);
            }
            if (! is_array($field)) {
                throw new InvalidRelationshipDefinition($name, "field \"{$key}\" must be a map");
            }
            $built[$key] = self::field($key, $field, $name);
        }

        return $built;
    }

    /**
     * @param  array<mixed, mixed>  $field
     */
    private static function field(string $key, array $field, string $name): RelationshipField
    {
        self::onlyKeys(
            $field,
            ['value_type', 'max_length', 'not_after', 'required', 'visibility', 'label', 'help', 'order', 'group', 'options'],
            $name,
            "unknown field key on \"{$key}\"",
        );
        $type = $field['value_type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::VALUE_TYPES, true)) {
            throw new InvalidRelationshipDefinition($name, "field \"{$key}\" value_type must be one of ".implode(', ', self::VALUE_TYPES));
        }
        if (! is_bool($field['required'] ?? null)) {
            throw new InvalidRelationshipDefinition($name, "field \"{$key}\" required must be a boolean");
        }
        if (! is_string($field['visibility'] ?? null) || ! in_array($field['visibility'], ['view', 'manage'], true)) {
            throw new InvalidRelationshipDefinition($name, "field \"{$key}\" visibility must be view or manage");
        }
        if (! is_string($field['label'] ?? null) || $field['label'] === '') {
            throw new InvalidRelationshipDefinition($name, "field \"{$key}\" label must be a non-empty string");
        }
        if (! is_int($field['order'] ?? null)) {
            throw new InvalidRelationshipDefinition($name, "field \"{$key}\" order must be an integer");
        }
        $help = self::optionalText($field['help'] ?? null, $name, "field \"{$key}\" help");
        $group = self::optionalText($field['group'] ?? null, $name, "field \"{$key}\" group");
        $ceiling = match ($type) {
            'text' => 255,
            'long_text' => 2000,
            default => null,
        };
        $max = $field['max_length'] ?? null;
        if ($ceiling === null && $max !== null) {
            throw new InvalidRelationshipDefinition($name, "field \"{$key}\" does not take max_length");
        }
        if ($ceiling !== null && (! is_int($max) || $max < 1 || $max > $ceiling)) {
            throw new InvalidRelationshipDefinition($name, "field \"{$key}\" max_length must be an integer from 1 to {$ceiling}");
        }
        $notAfter = $field['not_after'] ?? null;
        if ($type !== 'date' && $notAfter !== null) {
            throw new InvalidRelationshipDefinition($name, "field \"{$key}\" does not take not_after");
        }
        if ($type === 'date' && $notAfter !== null && $notAfter !== 'today') {
            throw new InvalidRelationshipDefinition($name, "field \"{$key}\" not_after must be today");
        }
        $options = [];
        if ($type === 'choice') {
            $raw = $field['options'] ?? null;
            if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
                throw new InvalidRelationshipDefinition($name, "field \"{$key}\" options must be a non-empty list");
            }
            foreach ($raw as $option) {
                if (! is_string($option) || $option === '' || in_array($option, $options, true)) {
                    throw new InvalidRelationshipDefinition($name, "field \"{$key}\" options must be unique and non-empty");
                }
                $options[] = $option;
            }
        } elseif (array_key_exists('options', $field)) {
            throw new InvalidRelationshipDefinition($name, "field \"{$key}\" does not take options");
        }

        return new RelationshipField(
            $key, $type, is_int($max) ? $max : null, is_string($notAfter) ? $notAfter : null,
            $field['required'], $field['visibility'], $field['label'], $help, $field['order'], $group, $options,
        );
    }

    private static function optionalText(mixed $value, string $name, string $rule): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '') {
            throw new InvalidRelationshipDefinition($name, "{$rule} must be a non-empty string when present");
        }

        return $value;
    }

    /**
     * @return array{Capability, Capability}
     */
    private static function capabilities(mixed $capabilities, string $name): array
    {
        if (! is_array($capabilities)) {
            throw new InvalidRelationshipDefinition($name, 'capabilities must be a map');
        }
        self::onlyKeys($capabilities, ['view', 'manage'], $name, 'unknown capabilities key');
        $view = self::capability($capabilities['view'] ?? null, $name, 'view');
        $manage = self::capability($capabilities['manage'] ?? null, $name, 'manage');
        if ($view === $manage) {
            throw new InvalidRelationshipDefinition($name, 'view and manage capabilities must differ');
        }

        return [$view, $manage];
    }

    private static function capability(mixed $value, string $name, string $which): Capability
    {
        if (! $value instanceof Capability || ! in_array($value->value, self::RELATIONSHIP_CAPABILITIES, true)) {
            throw new InvalidRelationshipDefinition($name, "capabilities.{$which} must be a relationship capability");
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private static function vocabulary(mixed $values, array $allowed, string $name, string $noun): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new InvalidRelationshipDefinition($name, "{$noun}s must be a list");
        }
        $built = [];
        foreach ($values as $value) {
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                throw new InvalidRelationshipDefinition($name, "unknown {$noun}");
            }
            if (in_array($value, $built, true)) {
                throw new InvalidRelationshipDefinition($name, "repeated {$noun} \"{$value}\"");
            }
            $built[] = $value;
        }

        return $built;
    }

    private static function defaultRole(mixed $value, string $name): void
    {
        // The allowlist stays empty until WP2B. Null is the only legal value, so a definition
        // cannot name a role at all. When a role becomes legal, the same rule requires
        // intake, status and default_role among the verified operations (F5), because each can grant authority.
        if ($value === 'missing' || $value !== null) {
            throw new InvalidRelationshipDefinition($name, 'default_role must be null or a provisionable role');
        }
    }

    private static function presentation(mixed $presentation, string $name): int
    {
        if (! is_array($presentation)) {
            throw new InvalidRelationshipDefinition($name, 'presentation must be a map');
        }
        self::onlyKeys($presentation, ['position'], $name, 'unknown presentation key');
        if (! is_int($presentation['position'] ?? null)) {
            throw new InvalidRelationshipDefinition($name, 'presentation.position must be an integer');
        }

        return $presentation['position'];
    }

    /**
     * @return list<string>
     */
    private static function components(mixed $components, string $name): array
    {
        if (! is_array($components) || ! array_is_list($components)) {
            throw new InvalidRelationshipDefinition($name, 'components must be a list');
        }
        foreach ($components as $component) {
            // The allowlist is empty until a component is part of the contract, so every entry is unknown.
            if (! is_string($component) || ! in_array($component, self::COMPONENTS, true)) { // @phpstan-ignore booleanOr.alwaysTrue, function.impossibleType
                throw new InvalidRelationshipDefinition($name, 'unknown component');
            }
        }

        return [];
    }
}
