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
    case AccountInvited = 'account.invited';
    case AccountDisabled = 'account.disabled';
    case InvitationAccepted = 'invitation.accepted';
    case PasswordResetRequested = 'password.reset_requested';
    case PasswordResetCompleted = 'password.reset_completed';
    case PasswordResetFailed = 'password.reset_failed';
    case PasswordChanged = 'password.changed';
}
