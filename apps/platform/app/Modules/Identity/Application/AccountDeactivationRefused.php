<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/** A deactivation guard vetoed the change. Nothing was modified. */
final class AccountDeactivationRefused extends RuntimeException
{
    public function __construct(string $reason = 'This account cannot be deactivated.')
    {
        parent::__construct($reason);
    }
}
