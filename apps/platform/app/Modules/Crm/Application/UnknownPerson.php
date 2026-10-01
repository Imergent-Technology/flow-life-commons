<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use RuntimeException;

/** There is no Person with that id. Identity is authoritative for whether a Person exists. */
final class UnknownPerson extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no such Person.');
    }
}
