<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\SourcedRoleGrantRepository;

/**
 * The sourced grants of particular source instances, for a relationship view (ADR 0038, F13).
 * Read-only. It has no route.
 */
final readonly class SourcedRoleGrantsOf
{
    public function __construct(private SourcedRoleGrantRepository $grants) {}

    /**
     * @param  list<RoleGrantSource>  $sources
     * @return list<SourcedRoleGrantRecord>
     */
    public function __invoke(array $sources): array
    {
        $pairs = array_map(
            static fn (RoleGrantSource $source): array => [$source->type->value, $source->id],
            $sources,
        );

        return array_map(SourcedRoleGrantRecord::from(...), $this->grants->forSources($pairs));
    }
}
