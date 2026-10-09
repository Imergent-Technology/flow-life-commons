<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipDefinition;
use App\Modules\Relationships\Domain\RelationshipField;

/**
 * The presentation contract for one type (ADR 0038, F13). `provisionDefaultRole` is a hint.
 * It is false for every type in WP1, because no type has a default role yet, and no mutation reads it.
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
