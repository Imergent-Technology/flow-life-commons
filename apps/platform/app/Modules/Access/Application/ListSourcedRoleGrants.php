<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\SourcedRoleGrantRepository;

/**
 * Every sourced grant of one source type, including a role key the catalog no longer
 * knows. Read-only. For `relationships:check` (ADR 0038, F15); it has no route.
 */
final readonly class ListSourcedRoleGrants
{
    public function __construct(private SourcedRoleGrantRepository $grants) {}

    /**
     * @return list<SourcedRoleGrantRecord>
     */
    public function __invoke(RoleGrantSourceType $type): array
    {
        return array_map(
            SourcedRoleGrantRecord::from(...),
            $this->grants->forSourceType($type->value),
        );
    }
}
