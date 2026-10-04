<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

final class OrderMismatch extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The order you sent does not match what is there now. Reload and try again.');
    }
}
