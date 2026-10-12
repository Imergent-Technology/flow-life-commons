<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipId;

/**
 * What managing one relationship needs, and nothing the manage capability is not owed (ADR 0038, F13, A4).
 * No history and no provenance. `basics` is the Person's primary email and phone, present only for a type that edits
 * basic details (ADR 0038, F13, P5) and null otherwise.
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
        public ?DefaultRoleState $defaultRole = null,
        public ?BasicDetails $basics = null,
    ) {}
}
