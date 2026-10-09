<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

/** How much a deletion removed, for the `relationship.deleted` event. Counts only: never a value. */
final readonly class DeletionCounts
{
    public function __construct(
        public int $history,
        public int $fields,
        public int $decisions,
    ) {}
}
