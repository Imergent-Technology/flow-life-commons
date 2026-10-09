<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * One relationship instance as stored (ADR 0038, F6). Fields are canonical text keyed by the
 * type's field key. History is complete: the creation row and one row per status change.
 */
final readonly class RelationshipRecord
{
    /**
     * @param  array<string, string>  $fields
     * @param  list<StatusChange>  $history
     */
    public function __construct(
        public RelationshipId $id,
        public PersonId $personId,
        public string $typeKey,
        public string $status,
        public int $revision,
        public DateTimeImmutable $statusChangedAt,
        public PersonId $statusChangedBy,
        public DateTimeImmutable $createdAt,
        public PersonId $createdBy,
        public DateTimeImmutable $updatedAt,
        public PersonId $updatedBy,
        public array $fields,
        public array $history,
    ) {}

    public function withStatus(string $status, PersonId $by, DateTimeImmutable $at): self
    {
        return new self(
            $this->id, $this->personId, $this->typeKey, $status, $this->revision + 1,
            $at, $by, $this->createdAt, $this->createdBy, $at, $by, $this->fields,
            [...$this->history, new StatusChange($this->status, $status, $at, $by)],
        );
    }

    /** @param  array<string, string>  $fields */
    public function withFields(array $fields, PersonId $by, DateTimeImmutable $at): self
    {
        return new self(
            $this->id, $this->personId, $this->typeKey, $this->status, $this->revision + 1,
            $this->statusChangedAt, $this->statusChangedBy, $this->createdAt, $this->createdBy,
            $at, $by, $fields, $this->history,
        );
    }
}
