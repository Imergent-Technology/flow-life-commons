<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** Intake named a Person Identity does not have (ADR 0038, Part H). */
final class UnknownRelationshipPerson extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such Person.');
    }
}
