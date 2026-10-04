<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

final class UnknownCategory extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such category to put the Pack in.');
    }
}
