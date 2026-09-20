<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/**
 * An Account already holds this email address (case variants included). Nothing was created,
 * and the existing Account was not touched: an invitation never repurposes or merges one.
 */
final class EmailAlreadyInUse extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('An account already uses this email address.');
    }
}
