<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

final class DuplicateCategory extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A category with that name already exists.');
    }
}
