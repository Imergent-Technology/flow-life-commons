<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/**
 * A default-role grant against a relationship that is not in a qualifying state
 * (ADR 0038, K8). Decided from the locked row, before Access is asked, and nothing is written.
 */
final class RelationshipNotActive extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A default role can be granted only while the relationship is active.');
    }
}
