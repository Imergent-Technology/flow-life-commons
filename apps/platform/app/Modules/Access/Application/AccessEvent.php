<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/**
 * Security event types Access records. Access owns this vocabulary; Audit only stores the
 * strings. Recorded only when state actually changed.
 */
enum AccessEvent: string
{
    case RoleGranted = 'role.granted';
    case RoleRevoked = 'role.revoked';
    case AdministratorBootstrapped = 'administrator.bootstrapped';
}
