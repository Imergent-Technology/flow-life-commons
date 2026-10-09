<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** Attaching an existing Person must be confirmed. Nothing was created (ADR 0038, P3). */
final class ConfirmationRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Confirm that this existing Person should be recorded.');
    }
}
