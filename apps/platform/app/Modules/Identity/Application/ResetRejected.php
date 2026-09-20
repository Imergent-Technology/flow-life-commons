<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/**
 * A password reset could not be completed. ONE outcome for every reason (unknown address, an Account
 * that cannot be reset, no token, a wrong token, an expired one), so a caller cannot use the difference
 * to learn which addresses have accounts.
 */
final class ResetRejected extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The password reset link is not valid.');
    }
}
