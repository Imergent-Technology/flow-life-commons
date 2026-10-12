<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\StoredRoleProvision;

/** Whether a default-role decision changed the stored row. The grant history stays in Access. */
final readonly class DefaultRoleOutcome
{
    /** @param  list<StoredRoleProvision>  $provisions */
    public function __construct(
        public bool $decisionChanged,
        public array $provisions,
    ) {}
}
