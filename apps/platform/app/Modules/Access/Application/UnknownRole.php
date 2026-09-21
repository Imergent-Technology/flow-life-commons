<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use RuntimeException;

/** A role key that is not in the code-owned catalog: refused, and nothing was changed. Never granted, never guessed. */
final class UnknownRole extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The role does not exist in the catalog');
    }
}
