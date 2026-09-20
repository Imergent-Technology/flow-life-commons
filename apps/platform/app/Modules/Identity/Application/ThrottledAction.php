<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** The credential endpoints that are rate limited, each with its own counters and limits. */
enum ThrottledAction: string
{
    case InvitationAcceptance = 'invitation_acceptance';
    case PasswordResetRequest = 'password_reset_request';
    case PasswordResetCompletion = 'password_reset_completion';
}
