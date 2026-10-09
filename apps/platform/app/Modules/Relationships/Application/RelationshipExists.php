<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** This Person already has a relationship of this type. Nothing was written (ADR 0038, F6). */
final class RelationshipExists extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Person already has this relationship.');
    }
}
