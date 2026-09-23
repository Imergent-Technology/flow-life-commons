<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

use RuntimeException;

/** Zero rows changed because a revocation was already committed, by this caller or another. */
final class GrantAlreadyRevoked extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This membership grant has already been revoked.');
    }
}
