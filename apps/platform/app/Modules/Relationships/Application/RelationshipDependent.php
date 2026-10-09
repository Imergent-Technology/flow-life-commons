<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipId;

/**
 * A later module whose records reference a relationship registers one of these (ADR 0038, F12).
 * G10 registers none. A dependent is not a grant: grants are withdrawn, not a reason to refuse.
 */
interface RelationshipDependent
{
    public function blocksDeletion(RelationshipId $id): bool;
}
