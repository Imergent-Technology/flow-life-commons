<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use App\Modules\Access\Application\Capability;

/**
 * One validated relationship type (ADR 0038, F3). Immutable. Built only by `DefinitionSchema`,
 * so a definition that reached a use case has already passed every catalog rule.
 */
final readonly class RelationshipDefinition
{
    /**
     * @param  array{singular: string, plural: string, description: string, help: ?string}  $labels
     * @param  array<string, RelationshipState>  $states
     * @param  list<string>  $initialStates
     * @param  array<string, list<string>>  $transitions
     * @param  array<string, RelationshipField>  $fields
     * @param  list<string>  $features
     * @param  list<string>  $verification
     * @param  list<string>  $components
     * @param  ?string  $defaultRole  null until a type names a provisionable role (WP2B)
     */
    public function __construct(
        public RelationshipType $type,
        public string $slug,
        public int $version,
        public array $labels,
        public array $states,
        public array $initialStates,
        public array $transitions,
        public array $fields,
        public Capability $viewCapability,
        public Capability $manageCapability,
        public array $features,
        public array $verification,
        public bool $deletion,
        public int $position,
        public array $components,
        public ?string $defaultRole,
    ) {}

    public function isInitial(string $status): bool
    {
        return in_array($status, $this->initialStates, true);
    }

    public function allowsTransition(string $from, string $to): bool
    {
        return in_array($to, $this->transitions[$from] ?? [], true);
    }

    public function qualifies(string $status): bool
    {
        $state = $this->states[$status] ?? null;

        return $state === null ? false : $state->qualifies;
    }

    public function verifies(string $operation): bool
    {
        return in_array($operation, $this->verification, true);
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function field(string $key): ?RelationshipField
    {
        return $this->fields[$key] ?? null;
    }
}
