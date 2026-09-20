<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

final class AccountNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such account.');
    }
}
