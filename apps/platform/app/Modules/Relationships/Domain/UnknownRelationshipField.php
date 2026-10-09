<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use RuntimeException;

/** A write named a field the relationship's own type does not have (ADR 0038, F7). */
final class UnknownRelationshipField extends RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('That field is not part of this relationship.');
    }
}
