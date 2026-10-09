<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use DateTimeImmutable;

/** One history row as a view may show it: statuses, when, and who, by name. */
final readonly class RelationshipHistoryEntry
{
    public function __construct(
        public ?string $fromStatus,
        public string $toStatus,
        public DateTimeImmutable $changedAt,
        public NamedPerson $changedBy,
    ) {}
}
