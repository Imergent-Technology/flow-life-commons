<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\Capability;
use App\Modules\Relationships\Domain\DefinitionSchema;
use App\Modules\Relationships\Domain\InvalidRelationshipDefinition;
use App\Modules\Relationships\Domain\RelationshipDefinition;
use App\Modules\Relationships\Domain\RelationshipType;

/**
 * The closed registry of relationship types (ADR 0038, F2, F3). Built once per application
 * boot and never written at runtime. A document that fails, or a set that shares a type,
 * a slug or a capability, is refused by name: the application does not boot with a type missing.
 */
final class RelationshipCatalog
{
    /** Tagged container binding for every {@see DefinitionSource}. */
    public const string SOURCE = 'relationships.definitions';

    /** @param  array<string, RelationshipDefinition>  $byType */
    private function __construct(private readonly array $byType) {}

    /** @param  iterable<DefinitionSource>  $sources */
    public static function load(iterable $sources): self
    {
        $definitions = [];
        foreach ($sources as $source) {
            foreach ($source->documents() as $document) {
                $name = is_string($document['type'] ?? null) && $document['type'] !== '' ? $document['type'] : 'untyped';
                $definitions[] = DefinitionSchema::check($document, $name);
            }
        }

        $byType = [];
        $slugs = [];
        $capabilities = [];
        foreach ($definitions as $definition) {
            $key = $definition->type->key;
            if (array_key_exists($key, $byType)) {
                throw new InvalidRelationshipDefinition($key, 'type is already defined');
            }
            if (array_key_exists($definition->slug, $slugs)) {
                throw new InvalidRelationshipDefinition($key, 'slug is already used');
            }
            foreach ([$definition->viewCapability, $definition->manageCapability] as $capability) {
                $owner = $capabilities[$capability->value] ?? null;
                if ($owner !== null) {
                    throw new InvalidRelationshipDefinition($key, "capability {$capability->value} already serves {$owner}");
                }
                $capabilities[$capability->value] = $key;
            }
            $byType[$key] = $definition;
            $slugs[$definition->slug] = $key;
        }

        return new self($byType);
    }

    public function type(string $key): ?RelationshipType
    {
        return $this->byType[$key]->type ?? null;
    }

    public function definition(RelationshipType $type): RelationshipDefinition
    {
        return $this->byType[$type->key] ?? throw new InvalidRelationshipDefinition($type->key, 'type is not in the catalog');
    }

    public function bySlug(string $slug): ?RelationshipDefinition
    {
        foreach ($this->byType as $definition) {
            if ($definition->slug === $slug) {
                return $definition;
            }
        }

        return null;
    }

    public function knows(string $typeKey): bool
    {
        return array_key_exists($typeKey, $this->byType);
    }

    /**
     * @return list<RelationshipDefinition>
     */
    public function all(): array
    {
        $definitions = array_values($this->byType);
        usort($definitions, fn (RelationshipDefinition $a, RelationshipDefinition $b): int => $a->position <=> $b->position ?: $a->type->key <=> $b->type->key);

        return $definitions;
    }

    public function capabilityServes(Capability $capability): ?RelationshipType
    {
        foreach ($this->byType as $definition) {
            if ($definition->viewCapability === $capability || $definition->manageCapability === $capability) {
                return $definition->type;
            }
        }

        return null;
    }
}
