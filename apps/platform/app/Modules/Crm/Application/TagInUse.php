<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use RuntimeException;

final class TagInUse extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That tag is on at least one Person, so it cannot be deleted.');
    }
}
