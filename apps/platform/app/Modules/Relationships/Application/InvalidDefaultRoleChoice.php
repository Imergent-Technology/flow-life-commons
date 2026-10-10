<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** The request named `default_role` with a value other than grant, decline or defer. */
final class InvalidDefaultRoleChoice extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('default_role must be grant, decline or defer.');
    }
}
