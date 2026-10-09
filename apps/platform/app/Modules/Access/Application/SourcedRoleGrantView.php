<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use DateTimeImmutable;

/** A sourced grant as an operator sees it. It has no revoke action: withdrawal is a decision on the source. */
final readonly class SourcedRoleGrantView
{
    public function __construct(
        public RoleDescriptor $role,
        public string $sourceType,
        public string $sourceLabel,
        public string $sourceId,
        public ?string $grantedByAccountId,
        public DateTimeImmutable $grantedAt,
    ) {}
}
