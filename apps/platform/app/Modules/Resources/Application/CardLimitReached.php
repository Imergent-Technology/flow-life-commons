<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

final class CardLimitReached extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A Pack holds at most 100 Cards.');
    }
}
