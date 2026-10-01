<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use RuntimeException;

final class DuplicateContactMethod extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That Person already has that contact method.');
    }
}
