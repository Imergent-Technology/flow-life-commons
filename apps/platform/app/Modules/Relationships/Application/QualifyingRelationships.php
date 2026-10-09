<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\PersonId;

/**
 * The eligibility read (ADR 0038, F14). It authorizes nothing: the caller passes a Person it has
 * already resolved. It reads current rows on every call. A type the catalog does not know is
 * ignored. Its consumer is Resources' audience eligibility (WP4); nothing else may call it.
 *
 * @return list<RelationshipType>
 */
final readonly class QualifyingRelationships
{
    public function __construct(
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
    ) {}

    /**
     * @return list<RelationshipType>
     */
    public function __invoke(PersonId $person): array
    {
        $qualifying = [];
        foreach ($this->relationships->currentOf($person) as $row) {
            $type = $this->catalog->type($row['type']);
            if ($type === null) {
                continue;
            }
            if ($this->catalog->definition($type)->qualifies($row['status'])) {
                $qualifying[] = $type;
            }
        }
        $position = [];
        foreach ($this->catalog->all() as $definition) {
            $position[$definition->type->key] = $definition->position;
        }
        usort($qualifying, fn (RelationshipType $a, RelationshipType $b): int => ($position[$a->key] ?? 0) <=> ($position[$b->key] ?? 0));

        return $qualifying;
    }
}
