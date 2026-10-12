<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/**
 * Access refused `access.roles.assign` inside the grant (ADR 0038, K5). The relationship
 * transaction rolls back, so a refused grant leaves no decision and no relationship change.
 */
final class RoleProvisioningNotPermitted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Granting the default role needs permission to assign roles.');
    }
}
