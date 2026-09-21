<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use DateTimeImmutable;

/** A role a Person holds, as an operator sees it: the descriptor and when it was granted. */
final readonly class RoleAssignmentView
{
    public function __construct(
        public RoleDescriptor $role,
        public DateTimeImmutable $grantedAt,
    ) {}
}
