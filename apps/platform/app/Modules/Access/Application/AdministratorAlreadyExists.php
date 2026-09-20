<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use RuntimeException;

/** An administrator assignment already exists, and this was not an explicit recovery. */
final class AdministratorAlreadyExists extends RuntimeException
{
    public function __construct(public readonly int $assigned)
    {
        parent::__construct('An administrator already exists.');
    }
}
