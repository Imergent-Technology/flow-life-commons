<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** The status is not an initial state, or not a transition the type allows (ADR 0038, F9). */
final class TransitionNotAllowed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That status is not allowed.');
    }
}
