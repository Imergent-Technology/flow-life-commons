<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\ListSourcedRoleGrants;
use App\Modules\Access\Application\RoleGrantSourceType;
use App\Modules\Relationships\Domain\FieldValues;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\StoredRoleProvision;

/**
 * The read-only integrity check (ADR 0038, F15). It writes nothing and reports no field value,
 * name or contact. Grant rows are read through Access's list, never from Access's table.
 */
final readonly class CheckRelationships
{
    public function __construct(
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private ListSourcedRoleGrants $grants,
    ) {}

    /**
     * @return list<string>
     */
    public function __invoke(): array
    {
        $now = RelationshipTime::now();
        $findings = [];
        $unknownTypes = [];
        foreach ($this->relationships->storedRelationships() as $row) {
            $type = $this->catalog->type($row['type']);
            if ($type === null) {
                $unknownTypes[$row['type']] = true;

                continue;
            }
            if (! array_key_exists($row['status'], $this->catalog->definition($type)->states)) {
                $findings[] = "unknown status {$row['status']} on {$row['type']}";
            }
        }
        foreach (array_keys($unknownTypes) as $type) {
            $findings[] = "unknown relationship type {$type}";
        }
        foreach ($this->relationships->storedFieldValues() as $row) {
            $type = $this->catalog->type($row['type']);
            if ($type === null) {
                continue;
            }
            $field = $this->catalog->definition($type)->field($row['field']);
            if ($field === null) {
                $findings[] = "unknown field {$row['field']} on {$row['type']}";

                continue;
            }
            if (! FieldValues::acceptsStored($field, $row['value'], $now)) {
                $findings[] = "invalid value for {$row['field']} on {$row['type']}";
            }
        }

        $instances = [];
        foreach ($this->relationships->storedInstances() as $instance) {
            $instances[$instance['id']] = $instance;
        }
        $decisions = [];
        foreach ($this->relationships->storedProvisions() as $provision) {
            $decisions[$provision['relationship_id']."\0".$provision['role']] = $provision['decision'];
        }
        $matched = [];
        foreach (($this->grants)(RoleGrantSourceType::Relationship) as $grant) {
            $instance = $instances[$grant->sourceId] ?? null;
            if ($instance === null) {
                $findings[] = "sourced grant {$grant->sourceId} has no relationship";

                continue;
            }
            $type = $this->catalog->type($instance['type']);
            $definition = $type === null ? null : $this->catalog->definition($type);
            $defaultRole = $definition?->defaultRole?->value;
            $qualifies = $definition !== null && $definition->qualifies($instance['status']);
            if (! $qualifies) {
                $findings[] = "sourced grant {$grant->sourceId} relationship is not qualifying";
            }
            if ($defaultRole === null || $grant->roleKey !== $defaultRole) {
                $findings[] = "sourced grant {$grant->sourceId} role is not the current default role";
            }
            $decision = $decisions[$grant->sourceId."\0".($defaultRole ?? '')] ?? null;
            if ($decision !== StoredRoleProvision::Granted) {
                $findings[] = "sourced grant {$grant->sourceId} decision is not granted";
            }
            if ($grant->personId->value !== $instance['person_id']) {
                $findings[] = "sourced grant {$grant->sourceId} person differs from the relationship";
            }
            if ($qualifies && $decision === StoredRoleProvision::Granted && $defaultRole !== null && $grant->roleKey === $defaultRole && $grant->personId->value === $instance['person_id']) {
                $matched[$grant->sourceId] = true;
            }
        }
        foreach ($instances as $id => $instance) {
            $type = $this->catalog->type($instance['type']);
            if ($type === null) {
                continue;
            }
            $definition = $this->catalog->definition($type);
            $role = $definition->defaultRole;
            if ($role === null || ! $definition->qualifies($instance['status'])) {
                continue;
            }
            if (($decisions[$id."\0".$role->value] ?? null) !== StoredRoleProvision::Granted) {
                continue;
            }
            if (! isset($matched[$id])) {
                $findings[] = "relationship {$id} is granted without a matching grant";
            }
        }
        sort($findings);

        return $findings;
    }
}
