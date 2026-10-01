<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use RuntimeException;

final class ContactMethodNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such contact method for that Person.');
    }
}
