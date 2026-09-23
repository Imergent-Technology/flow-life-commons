<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use RuntimeException;

final class GrantNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such membership grant.');
    }
}
