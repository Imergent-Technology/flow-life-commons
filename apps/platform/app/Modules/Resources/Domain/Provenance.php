<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * Who created a Resource thing and who last edited it, and when (ADR 0037, decision 54). Person ids with no foreign key
 * (ADR 0021): organizational content is not owned by its author, so this never confers authority, it only says what happened.
 * Ordering a thing is not editing it, so a reorder leaves this alone.
 */
final readonly class Provenance
{
    public function __construct(
        public PersonId $createdBy,
        public DateTimeImmutable $createdAt,
        public PersonId $updatedBy,
        public DateTimeImmutable $updatedAt,
    ) {}

    public static function created(PersonId $by, DateTimeImmutable $now): self
    {
        return new self($by, $now, $by, $now);
    }

    public function touched(PersonId $by, DateTimeImmutable $now): self
    {
        return new self($this->createdBy, $this->createdAt, $by, $now);
    }
}
