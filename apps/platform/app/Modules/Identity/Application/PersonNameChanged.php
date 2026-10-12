<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/**
 * A rename named the display name it expected, and the Person's name is now something else. Nothing was written.
 * It carries the current name so the caller can show it to someone who already may read it.
 */
final class PersonNameChanged extends RuntimeException
{
    public function __construct(public readonly string $currentName)
    {
        parent::__construct('The name has changed since it was read.');
    }
}
