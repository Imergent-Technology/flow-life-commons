<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use RuntimeException;

/** A credential endpoint's rate limit is engaged. The caller is told when to try again, and nothing else. */
final class TooManyAttempts extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many attempts.');
    }
}
