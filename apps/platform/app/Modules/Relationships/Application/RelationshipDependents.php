<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipId;

/** The module's dependents registry. Empty until a later module registers a check. */
final readonly class RelationshipDependents
{
    /** @param  iterable<RelationshipDependent>  $dependents */
    public function __construct(private iterable $dependents) {}

    public function blocks(RelationshipId $id): bool
    {
        foreach ($this->dependents as $dependent) {
            if ($dependent->blocksDeletion($id)) {
                return true;
            }
        }

        return false;
    }
}
