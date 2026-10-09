<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use App\Shared\Domain\PersonId;

/**
 * Persistence for relationship instances. The use case holds the transaction and the row lock;
 * these methods do not open one of their own.
 */
interface RelationshipRepository
{
    public function find(PersonId $person, string $typeKey): ?RelationshipRecord;

    /** Locking read of the row for (person, type), with its fields and history (ADR 0038, T1). */
    public function lock(PersonId $person, string $typeKey): ?RelationshipRecord;

    public function add(RelationshipRecord $record): void;

    /** Writes the new status, revision and exactly one history row. Conditional on the revision it replaces. */
    public function saveStatus(RelationshipRecord $updated, int $expectedRevision): bool;

    /**
     * Writes the new revision, the changed field values and the cleared keys. Conditional on the
     * revision it replaces. Unchanged fields keep their provenance.
     *
     * @param  array<string, string>  $written
     * @param  list<string>  $cleared
     */
    public function saveFields(RelationshipRecord $updated, int $expectedRevision, array $written, array $cleared, PersonId $by, \DateTimeImmutable $at): bool;

    /** Children first, then the instance. Returns how many of each child were removed. */
    public function delete(RelationshipId $id): DeletionCounts;

    /**
     * Person ids holding this type, optionally in one status. The caller refuses a set larger
     * than a directory search can compose.
     *
     * @return list<string>
     */
    public function personIds(string $typeKey, ?string $status): array;

    /**
     * @param  list<string>  $personIds
     * @return array<string, array{id: string, status: string, status_changed_at: string}> person id => row, this type only
     */
    public function statusesFor(string $typeKey, array $personIds): array;

    /**
     * @return list<array{type: string, status: string}>
     */
    public function currentOf(PersonId $person): array;

    /**
     * @return list<array{type: string, status: string}>
     */
    public function storedRelationships(): array;

    /**
     * @return list<array{type: string, field: string, value: string}>
     */
    public function storedFieldValues(): array;
}
