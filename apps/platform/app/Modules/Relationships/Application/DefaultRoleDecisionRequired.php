<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/**
 * Entering a qualifying state for a type with a default role, without an explicit
 * grant, decline or defer (ADR 0038, K5). Nothing is inferred and nothing is written.
 */
final class DefaultRoleDecisionRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Choose whether to grant the default role.');
    }
}
