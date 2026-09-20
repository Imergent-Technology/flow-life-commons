<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use RuntimeException;

/** The operation would leave the platform with no active administrator (ADR 0020). */
final class LastAdministratorRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The platform must keep at least one active administrator.');
    }
}
