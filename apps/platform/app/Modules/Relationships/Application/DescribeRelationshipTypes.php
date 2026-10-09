<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Capability;
use App\Modules\Relationships\Domain\RelationshipField;
use App\Shared\Domain\Actor;

/**
 * Describes the types an Actor may view or manage (ADR 0038, F13, A6). It authorizes nothing
 * beyond the route's Console admission: it filters. `Capability::AssignRoles` is named here,
 * and only here, to compute the provisioning hint. The hint authorizes nothing. No type has a
 * default role in WP1, so the hint is false for every Actor.
 */
final readonly class DescribeRelationshipTypes
{
    public function __construct(
        private RelationshipCatalog $catalog,
        private Authorizer $authorizer,
    ) {}

    /**
     * @return list<RelationshipTypeView>
     */
    public function __invoke(Actor $actor): array
    {
        $mayAssignRoles = $this->authorizer->allows($actor, Capability::AssignRoles);
        $views = [];
        foreach ($this->catalog->all() as $definition) {
            $canView = $this->authorizer->allows($actor, $definition->viewCapability);
            $canManage = $this->authorizer->allows($actor, $definition->manageCapability);
            if (! $canView && ! $canManage) {
                continue;
            }
            $fields = array_values(array_filter(
                $definition->fields,
                fn (RelationshipField $field): bool => $canManage || $field->visibility === 'view',
            ));
            usort($fields, fn (RelationshipField $a, RelationshipField $b): int => $a->order <=> $b->order ?: $a->key <=> $b->key);
            $views[] = new RelationshipTypeView(
                $definition,
                $fields,
                $canView,
                $canManage,
                $canManage && $definition->deletion,
                $canManage && $mayAssignRoles && $definition->defaultRole !== null,
            );
        }

        return $views;
    }
}
