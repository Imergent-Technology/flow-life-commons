<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use RuntimeException;

final class NotAuthor extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only the person who wrote that can change it.');
    }
}
