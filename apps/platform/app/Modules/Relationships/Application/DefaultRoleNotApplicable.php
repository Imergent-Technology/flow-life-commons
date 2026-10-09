<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/**
 * The request carried a default-role decision for a type that has none, or alongside a status
 * that does not take one (ADR 0038, Part H). Nothing was written.
 */
final class DefaultRoleNotApplicable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This relationship has no default role to decide.');
    }
}
