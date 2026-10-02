<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use RuntimeException;

final class MessageRemoved extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That message has been removed.');
    }
}
