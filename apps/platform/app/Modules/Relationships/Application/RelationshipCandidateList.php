<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

/**
 * The manage-only name lookup (ADR 0038, P4, narrowed to names in WP1).
 * Email and phone matching arrives with CRM's delegated lookup (WP3). No contact value is returned.
 * `status` is this type only, or null when the Person has no relationship of the type.
 */
final readonly class RelationshipCandidateList
{
    /** @param  list<RelationshipCandidate>  $candidates */
    public function __construct(public array $candidates, public bool $truncated) {}
}
