<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** The credential endpoints that are rate limited, each with its own counters and limits. */
enum ThrottledAction: string
{
    case InvitationAcceptance = 'invitation_acceptance';
    case PasswordResetRequest = 'password_reset_request';
    case PasswordResetCompletion = 'password_reset_completion';
    case PasswordChange = 'password_change';

    /** A code presented to finish a sign-in, or to confirm an authenticator. Per Account. */
    case MfaChallenge = 'mfa_challenge';

    /** Password plus second factor presented to prove recent security verification. Per Account. */
    case SecurityVerification = 'security_verification';

    /**
     * An operator re-sending an Account's invitation. Unlike the others this is not a guess to bound:
     * the caller is authenticated, holds a capability and has proved themselves recently. What it
     * bounds is MAIL to somebody else's inbox, which is the one thing about this endpoint the operator
     * does not own. Per TARGET Account, so one address cannot be flooded, and per source address.
     */
    case InvitationReissue = 'invitation_reissue';
}
