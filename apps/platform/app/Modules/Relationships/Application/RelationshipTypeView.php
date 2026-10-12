<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipDefinition;
use App\Modules\Relationships\Domain\RelationshipField;

/**
 * The presentation contract for one type (ADR 0038, F13). `provisionDefaultRole` is a hint.
 * It is true only when the Actor may manage the type and Access would let them assign roles.
 * No mutation reads it.
 */
final readonly class RelationshipTypeView
{
    /** @param  list<RelationshipField>  $fields  the fields this Actor may see */
    public function __construct(
        public RelationshipDefinition $definition,
        public array $fields,
        public bool $canView,
        public bool $canManage,
        public bool $canDelete,
        public bool $provisionDefaultRole,
    ) {}
}
