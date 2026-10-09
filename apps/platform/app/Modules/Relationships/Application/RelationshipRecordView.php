<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipId;
use DateTimeImmutable;

/**
 * The view-capability read (ADR 0038, F13). History is included. Manage-only fields are not.
 * Basic contact details are not: that read is the CRM seam (WP3).
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
    ) {}
}
