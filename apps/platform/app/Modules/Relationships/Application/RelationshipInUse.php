<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** A registered dependent still references the relationship. Nothing was deleted (ADR 0038, F12). */
final class RelationshipInUse extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This relationship is still in use.');
    }
}
