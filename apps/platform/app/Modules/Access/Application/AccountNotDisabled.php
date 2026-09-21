<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use RuntimeException;

/** Re-enable was asked of an Account that is not disabled. Nothing was changed; the operator is told what really is. */
final class AccountNotDisabled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The account is not disabled.');
    }
}
