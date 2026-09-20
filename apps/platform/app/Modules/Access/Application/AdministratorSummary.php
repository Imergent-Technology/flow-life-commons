<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/** How many administrator assignments exist, and how many of those Accounts can actually sign in. */
final readonly class AdministratorSummary
{
    public function __construct(
        public int $assigned,
        public int $active,
    ) {}
}
