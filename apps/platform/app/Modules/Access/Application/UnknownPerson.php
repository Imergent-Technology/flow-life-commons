<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use RuntimeException;

final class UnknownPerson extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such person.');
    }
}
