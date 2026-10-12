<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipId;
use DateTimeImmutable;

/**
 * The view-capability read (ADR 0038, F13). History is included. Manage-only fields are not.
 * `basics` is the Person's primary email and phone, present only for a type that reads basic details (ADR 0038, F13, P2)
 * and null otherwise. Nothing else of the Person's contact data is in it.
 */
final readonly class RelationshipRecordView
{
    /**
     * @param  array<string, string>  $fields
     * @param  list<RelationshipHistoryEntry>  $history
     */
    public function __construct(
        public RelationshipId $id,
        public string $type,
        public int $definitionVersion,
        public NamedPerson $person,
        public string $status,
        public DateTimeImmutable $statusSince,
        public NamedPerson $statusBy,
        public int $revision,
        public DateTimeImmutable $createdAt,
        public NamedPerson $createdBy,
        public array $fields,
        public array $history,
        public ?DefaultRoleState $defaultRole = null,
        public ?BasicDetails $basics = null,
    ) {}
}
