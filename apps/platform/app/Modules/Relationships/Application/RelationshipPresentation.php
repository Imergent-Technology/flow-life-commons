<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Identity\Application\FindPeople;
use App\Modules\Relationships\Domain\RelationshipDefinition;
use App\Modules\Relationships\Domain\RelationshipRecord;
use App\Shared\Domain\PersonId;

/** Turns a stored relationship into the two reads, resolving names through Identity. */
final readonly class RelationshipPresentation
{
    public function __construct(private FindPeople $people) {}

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
        );
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
