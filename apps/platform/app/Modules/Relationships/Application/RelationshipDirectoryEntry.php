<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipId;
use DateTimeImmutable;

final readonly class RelationshipDirectoryEntry
{
    public function __construct(
        public RelationshipId $id,
        public NamedPerson $person,
        public string $status,
        public DateTimeImmutable $statusChangedAt,
    ) {}
}
