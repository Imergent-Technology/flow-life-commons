<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipId;

/**
 * What managing one relationship needs, and nothing the manage capability is not owed (ADR 0038, F13, A4).
 * No history and no provenance. Basic details arrive with the CRM seam (WP3), and only for a type that edits them.
 */
final readonly class RelationshipManagementView
{
    /**
     * @param  list<string>  $transitions
     * @param  array<string, string>  $fields  canonical text, including manage-only fields
     */
    public function __construct(
        public RelationshipId $id,
        public string $type,
        public NamedPerson $person,
        public string $status,
        public int $revision,
        public array $transitions,
        public array $fields,
    ) {}
}
