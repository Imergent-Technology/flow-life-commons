<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * Security event types Identity records (docs/architecture/identity-and-access.md,
 * "Auditing"). Identity owns this vocabulary; Audit only stores the strings, which keeps
 * Audit from having to know about Identity.
 */
enum IdentityEvent: string
{
    case AuthenticationSucceeded = 'authentication.succeeded';
    case AuthenticationFailed = 'authentication.failed';
    case AuthenticationLogout = 'authentication.logout';
    case AuthenticationRateLimited = 'authentication.rate_limited';
    case SessionAbsoluteExpired = 'session.absolute_expired';
}
