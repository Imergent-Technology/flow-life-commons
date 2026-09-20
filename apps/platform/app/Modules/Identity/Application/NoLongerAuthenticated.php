<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/**
 * The Account a request was authenticated as can no longer authenticate (it was disabled while the
 * request was in flight). Nothing was changed, and the caller is treated as not signed in.
 */
final class NoLongerAuthenticated extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The account can no longer sign in.');
    }
}
