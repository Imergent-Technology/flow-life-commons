<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** The directory's Person-id set is larger than a search can compose (ADR 0038, C3). Nothing was truncated. */
final class RelationshipSearchTooBroad extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That matches too many People to use as a search. Be more specific.');
    }
}
