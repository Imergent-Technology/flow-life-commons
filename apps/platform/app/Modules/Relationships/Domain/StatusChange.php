<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/** One row of business history. `from` is null only on the row that records creation (ADR 0038, F10). */
final readonly class StatusChange
{
    public function __construct(
        public ?string $fromStatus,
        public string $toStatus,
        public DateTimeImmutable $changedAt,
        public PersonId $changedBy,
    ) {}
}
