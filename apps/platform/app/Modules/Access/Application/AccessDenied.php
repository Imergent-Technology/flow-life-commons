<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use RuntimeException;

/** The Actor may not do this. Deliberately says nothing about which capability or why. */
final class AccessDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This action is unauthorized.');
    }
}
