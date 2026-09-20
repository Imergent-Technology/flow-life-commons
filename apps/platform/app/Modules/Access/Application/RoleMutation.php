<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/**
 * What a role mutation did. Both outcomes are success: granting a role already held and
 * revoking one not held are no-ops, and only a real change is audited.
 */
enum RoleMutation
{
    case Changed;
    case Unchanged;
}
