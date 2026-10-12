<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\RoleGrantSource;
use App\Modules\Access\Application\SourcedRoleGrantsOf;
use App\Modules\Crm\Application\Delegated\ReadPersonBasics;
use App\Modules\Identity\Application\FindPeople;
use App\Modules\Relationships\Domain\RelationshipDefinition;
use App\Modules\Relationships\Domain\RelationshipRecord;
use App\Shared\Domain\PersonId;

/** Turns a stored relationship into the two reads, resolving names through Identity. */
final readonly class RelationshipPresentation
{
    public function __construct(
        private FindPeople $people,
        private SourcedRoleGrantsOf $grants,
        private ReadPersonBasics $basics,
    ) {}

    public function management(RelationshipDefinition $definition, RelationshipRecord $record): RelationshipManagementView
    {
        return new RelationshipManagementView(
            $record->id,
            $definition->type->key,
            $this->name($record->personId),
            $record->status,
            $record->revision,
            $definition->transitions[$record->status] ?? [],
            $record->fields,
            $this->defaultRole($definition, $record),
            $definition->hasFeature('people.edit_basics') ? $this->basicsOf([$record->personId])[$record->personId->value] ?? null : null,
        );
    }

    public function record(RelationshipDefinition $definition, RelationshipRecord $record): RelationshipRecordView
    {
        $fields = [];
        foreach ($record->fields as $key => $value) {
            $field = $definition->field($key);
            if ($field !== null && $field->visibility === 'view') {
                $fields[$key] = $value;
            }
        }
        $authors = $this->names(array_map(fn ($change) => $change->changedBy, $record->history));
        $history = [];
        foreach ($record->history as $change) {
            $history[] = new RelationshipHistoryEntry(
                $change->fromStatus,
                $change->toStatus,
                $change->changedAt,
                $authors[$change->changedBy->value],
            );
        }

        return new RelationshipRecordView(
            $record->id,
            $definition->type->key,
            $definition->version,
            $this->name($record->personId),
            $record->status,
            $record->statusChangedAt,
            $this->name($record->statusChangedBy),
            $record->revision,
            $record->createdAt,
            $this->name($record->createdBy),
            $fields,
            $history,
            $this->defaultRole($definition, $record),
            $definition->hasFeature('people.read_basics') ? $this->basicsOf([$record->personId])[$record->personId->value] ?? null : null,
        );
    }

    /**
     * The type's own default role, its stored decision, and whether that source's grant
     * is in effect. Other roles and other sources are not read into the view.
     */
    private function defaultRole(RelationshipDefinition $definition, RelationshipRecord $record): ?DefaultRoleState
    {
        $role = $definition->defaultRole;
        if ($role === null) {
            return null;
        }
        $decision = null;
        foreach ($record->provisions as $provision) {
            if ($provision->role === $role->value) {
                $decision = $provision;
                break;
            }
        }
        $effective = false;
        foreach (($this->grants)([RoleGrantSource::relationship($record->id->value)]) as $grant) {
            if ($grant->roleKey === $role->value && $grant->personId->equals($record->personId)) {
                $effective = true;
            }
        }

        return new DefaultRoleState(
            $role->displayName(),
            $decision?->decision,
            $decision === null ? null : $this->name($decision->decidedBy),
            $decision?->decidedAt,
            $effective,
        );
    }

    /**
     * The three basic details of these Persons, in one CRM read. The caller has already decided that the Actor may read
     * them (ADR 0038, P2, P3): a view asks only for a type whose definition enables the feature.
     *
     * @param  list<PersonId>  $ids
     * @return array<string, BasicDetails>
     */
    public function basicsOf(array $ids): array
    {
        $details = [];
        foreach (($this->basics)($ids) as $key => $basics) {
            $details[$key] = new BasicDetails($basics->displayName, $basics->primaryEmail, $basics->primaryPhone);
        }

        return $details;
    }

    /**
     * @param  list<PersonId>  $ids
     * @return array<string, NamedPerson>
     */
    public function names(array $ids): array
    {
        $found = ($this->people)($ids);
        $names = [];
        foreach ($ids as $id) {
            $summary = $found[$id->value] ?? null;
            $names[$id->value] = new NamedPerson($id, $summary?->displayName);
        }

        return $names;
    }

    private function name(PersonId $id): NamedPerson
    {
        return $this->names([$id])[$id->value];
    }
}
