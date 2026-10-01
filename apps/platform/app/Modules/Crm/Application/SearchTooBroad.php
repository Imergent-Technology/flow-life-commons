<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use RuntimeException;

final class SearchTooBroad extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That matches too many People to use as a search. Be more specific.');
    }
}
