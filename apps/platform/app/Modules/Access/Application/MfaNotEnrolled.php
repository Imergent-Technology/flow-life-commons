<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use RuntimeException;

/** A reset was asked of an Account with no authenticator and no recovery codes, so there was nothing to reset. Nothing was changed. */
final class MfaNotEnrolled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This account has no second factor to reset.');
    }
}
