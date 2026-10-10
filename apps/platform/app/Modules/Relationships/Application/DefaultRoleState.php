<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use DateTimeImmutable;

/**
 * The default-role panel of one relationship (ADR 0038, F13). No other roles,
 * no capability list, and no relationship field values.
 */
final readonly class DefaultRoleState
{
    public function __construct(
        public string $displayName,
        public ?string $decision,
        public ?NamedPerson $decidedBy,
        public ?DateTimeImmutable $decidedAt,
        public bool $effective,
    ) {}
}
