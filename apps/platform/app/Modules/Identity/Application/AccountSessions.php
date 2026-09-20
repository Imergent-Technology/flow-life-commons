<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\AccountId;

/** The Guardian Console sessions belonging to an Account. */
interface AccountSessions
{
    /**
     * Ends every session belonging to the Account, so none can be used again. Sessions of other
     * Accounts, and anonymous ones, are left alone. Returns how many were removed.
     */
    public function revokeAllFor(AccountId $account): int;
}
