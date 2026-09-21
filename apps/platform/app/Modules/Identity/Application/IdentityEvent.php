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
    case SessionSuperseded = 'session.superseded';
    case AccountInvited = 'account.invited';
    case AccountDisabled = 'account.disabled';
    case AccountReenabled = 'account.reenabled';
    case InvitationReissued = 'invitation.reissued';
    case InvitationDeliveryFailed = 'invitation.delivery_failed';
    case InvitationAccepted = 'invitation.accepted';
    case PasswordResetRequested = 'password.reset_requested';
    case PasswordResetCompleted = 'password.reset_completed';
    case PasswordResetFailed = 'password.reset_failed';
    case PasswordChanged = 'password.changed';
    case MfaEnabled = 'mfa.enabled';
    case MfaChallengeFailed = 'mfa.challenge_failed';
    case MfaRecoveryCodeUsed = 'mfa.recovery_code_used';
    case MfaRecoveryCodesRegenerated = 'mfa.recovery_codes_regenerated';
    case MfaReplaced = 'mfa.replaced';
    case SecurityReverified = 'security.reverified';
    case SessionSecondFactorRequired = 'session.second_factor_required';
    case MfaAdministrativelyReset = 'mfa.administratively_reset';
    case MfaResetFromServer = 'mfa.reset_from_server';
}
