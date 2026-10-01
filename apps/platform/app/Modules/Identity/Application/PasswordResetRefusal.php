<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** Why an operator's request to send a password-reset email was refused. The value is the stable API code suffix. */
enum PasswordResetRefusal: string
{
    /** Still invited: it has no password to reset. Resetting would be a way round the invitation; send a new invitation instead. */
    case AccountInvited = 'account_invited';

    /** Disabled, so it cannot sign in and must not be recovered. Enable it first. */
    case AccountDisabled = 'account_disabled';

    /** A token was issued for the Account so recently that another must not be (the same one-a-minute rule as the public flow). */
    case RecentlyRequested = 'recently_requested';

    public function message(): string
    {
        return match ($this) {
            self::AccountInvited => 'This account has not accepted its invitation yet, so there is no password to reset. Send a new invitation instead.',
            self::AccountDisabled => 'This account is disabled. Enable it before sending a password reset email.',
            self::RecentlyRequested => 'A password reset email was sent to this account a moment ago. Wait a minute before sending another.',
        };
    }
}
