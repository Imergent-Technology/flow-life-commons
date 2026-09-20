<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/** The current password presented to change a password was wrong. Nothing was changed. */
final class CurrentPasswordIncorrect extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The current password is incorrect.');
    }
}
