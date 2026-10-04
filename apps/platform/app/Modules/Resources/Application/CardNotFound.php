<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use RuntimeException;

final class CardNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such Card in that Pack.');
    }
}
