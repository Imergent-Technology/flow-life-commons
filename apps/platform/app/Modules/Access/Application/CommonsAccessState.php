<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\ManagedAccount;

/**
 * A Person's Commons access, as a management decision needs it — never as the Account itself. Distinct from
 * `Identity\Domain\AccountStatus` on purpose: that enum is Identity's stored fact about an Account that already
 * exists; this is Access's answer to "where does this PERSON stand", which is also meaningful when no Account
 * exists at all (`NotInvited`, the one state `AccountStatus` cannot represent).
 */
enum CommonsAccessState: string
{
    /** No Commons Account yet: the existing-Person invitation may still be issued. */
    case NotInvited = 'not_invited';

    /** Invited, but has not set a password yet. */
    case Invited = 'invited';

    /** Has accepted the invitation and can sign in. */
    case Active = 'active';

    /** Cannot sign in. Still has an Account, so may not be invited again. */
    case Disabled = 'disabled';

    public static function of(?ManagedAccount $account): self
    {
        if ($account === null) {
            return self::NotInvited;
        }

        return self::from($account->status);
    }
}
