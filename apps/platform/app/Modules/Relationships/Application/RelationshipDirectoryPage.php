<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

/** One page of a type's directory. Names and status only (ADR 0038, WP1). Contact search is WP3. */
final readonly class RelationshipDirectoryPage
{
    /** @param  list<RelationshipDirectoryEntry>  $entries */
    public function __construct(
        public array $entries,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}
}
