<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use RuntimeException;

final class DuplicateTag extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A tag with that name already exists.');
    }
}
