<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/**
 * No relationship of this type for this Person. The same answer whether or not the Person exists,
 * so the route cannot be used to test that (ADR 0038, P3).
 */
final class RelationshipNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such relationship.');
    }
}
