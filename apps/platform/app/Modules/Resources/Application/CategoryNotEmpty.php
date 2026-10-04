<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

final class CategoryNotEmpty extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That category still holds Packs. Move or delete them first.');
    }
}
