<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Infrastructure;

use App\Modules\Relationships\Domain\DeletionCounts;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRecord;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\StatusChange;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use stdClass;

/**
 * Query builder, like Discussions: no model whose save() could write outside the paths provided.
 * The caller holds the transaction. `lock` takes the instance row and then its children, in a fixed order.
 */
final readonly class DatabaseRelationshipRepository implements RelationshipRepository
{
    private const string INSTANCES = 'person_relationships';

    private const string HISTORY = 'person_relationship_status_changes';

    private const string FIELDS = 'person_relationship_field_values';

    private const string DECISIONS = 'person_relationship_role_provisions';

    public function __construct(private ConnectionInterface $database) {}

    public function find(PersonId $person, string $typeKey): ?RelationshipRecord
    {
        $row = $this->database->table(self::INSTANCES)
            ->where('person_id', $person->value)
            ->where('relationship_type', $typeKey)
            ->first();

        return $row === null ? null : $this->hydrate($row, false);
    }

    public function lock(PersonId $person, string $typeKey): ?RelationshipRecord
    {
        $row = $this->database->table(self::INSTANCES)
            ->where('person_id', $person->value)
            ->where('relationship_type', $typeKey)
            ->lockForUpdate()
            ->first();

        return $row === null ? null : $this->hydrate($row, true);
    }

    public function add(RelationshipRecord $record): void
    {
        $this->database->table(self::INSTANCES)->insert([
            'id' => $record->id->value,
            'person_id' => $record->personId->value,
            'relationship_type' => $record->typeKey,
            'status' => $record->status,
            'revision' => $record->revision,
            'status_changed_at' => SqlTime::to($record->statusChangedAt),
            'status_changed_by' => $record->statusChangedBy->value,
            'created_at' => SqlTime::to($record->createdAt),
            'created_by' => $record->createdBy->value,
            'updated_at' => SqlTime::to($record->updatedAt),
            'updated_by' => $record->updatedBy->value,
        ]);
        foreach ($record->history as $change) {
            $this->insertChange($record->id, $change);
        }
        foreach ($record->fields as $key => $value) {
            $this->database->table(self::FIELDS)->insert([
                'relationship_id' => $record->id->value,
                'field_key' => $key,
                'value' => $value,
                'updated_at' => SqlTime::to($record->updatedAt),
                'updated_by' => $record->updatedBy->value,
            ]);
        }
    }

    public function saveStatus(RelationshipRecord $updated, int $expectedRevision): bool
    {
        $affected = $this->database->table(self::INSTANCES)
            ->where('id', $updated->id->value)
            ->where('revision', $expectedRevision)
            ->update([
                'status' => $updated->status,
                'revision' => $updated->revision,
                'status_changed_at' => SqlTime::to($updated->statusChangedAt),
                'status_changed_by' => $updated->statusChangedBy->value,
                'updated_at' => SqlTime::to($updated->updatedAt),
                'updated_by' => $updated->updatedBy->value,
            ]);
        if ($affected !== 1) {
            return false;
        }
        $change = $updated->history[array_key_last($updated->history)] ?? null;
        if ($change instanceof StatusChange) {
            $this->insertChange($updated->id, $change);
        }

        return true;
    }

    public function saveFields(RelationshipRecord $updated, int $expectedRevision, array $written, array $cleared, PersonId $by, DateTimeImmutable $at): bool
    {
        $affected = $this->database->table(self::INSTANCES)
            ->where('id', $updated->id->value)
            ->where('revision', $expectedRevision)
            ->update([
                'revision' => $updated->revision,
                'updated_at' => SqlTime::to($updated->updatedAt),
                'updated_by' => $updated->updatedBy->value,
            ]);
        if ($affected !== 1) {
            return false;
        }
        if ($cleared !== []) {
            $this->database->table(self::FIELDS)
                ->where('relationship_id', $updated->id->value)
                ->whereIn('field_key', $cleared)
                ->delete();
        }
        foreach ($written as $key => $value) {
            $existing = $this->database->table(self::FIELDS)
                ->where('relationship_id', $updated->id->value)
                ->where('field_key', $key)
                ->exists();
            $row = [
                'value' => $value,
                'updated_at' => SqlTime::to($at),
                'updated_by' => $by->value,
            ];
            if ($existing) {
                $this->database->table(self::FIELDS)
                    ->where('relationship_id', $updated->id->value)
                    ->where('field_key', $key)
                    ->update($row);
            } else {
                $this->database->table(self::FIELDS)->insert([
                    'relationship_id' => $updated->id->value,
                    'field_key' => $key,
                    ...$row,
                ]);
            }
        }

        return true;
    }

    public function delete(RelationshipId $id): DeletionCounts
    {
        $history = $this->database->table(self::HISTORY)->where('relationship_id', $id->value)->count();
        $fields = $this->database->table(self::FIELDS)->where('relationship_id', $id->value)->count();
        $decisions = $this->database->table(self::DECISIONS)->where('relationship_id', $id->value)->count();
        $this->database->table(self::DECISIONS)->where('relationship_id', $id->value)->delete();
        $this->database->table(self::FIELDS)->where('relationship_id', $id->value)->delete();
        $this->database->table(self::HISTORY)->where('relationship_id', $id->value)->delete();
        $this->database->table(self::INSTANCES)->where('id', $id->value)->delete();

        return new DeletionCounts($history, $fields, $decisions);
    }

    public function personIds(string $typeKey, ?string $status): array
    {
        $query = $this->database->table(self::INSTANCES)->where('relationship_type', $typeKey);
        if ($status !== null) {
            $query->where('status', $status);
        }
        $ids = [];
        foreach ($query->orderBy('person_id')->pluck('person_id') as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function statusesFor(string $typeKey, array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }
        $rows = [];
        foreach ($this->database->table(self::INSTANCES)
            ->where('relationship_type', $typeKey)
            ->whereIn('person_id', $personIds)
            ->get(['person_id', 'id', 'status', 'status_changed_at']) as $row) {
            if (! is_string($row->person_id) || ! is_string($row->id) || ! is_string($row->status) || ! is_string($row->status_changed_at)) {
                continue;
            }
            $rows[$row->person_id] = [
                'id' => $row->id,
                'status' => $row->status,
                'status_changed_at' => $row->status_changed_at,
            ];
        }

        return $rows;
    }

    public function currentOf(PersonId $person): array
    {
        $rows = [];
        foreach ($this->database->table(self::INSTANCES)->where('person_id', $person->value)->orderBy('relationship_type')->get(['relationship_type', 'status']) as $row) {
            if (is_string($row->relationship_type) && is_string($row->status)) {
                $rows[] = ['type' => $row->relationship_type, 'status' => $row->status];
            }
        }

        return $rows;
    }

    public function storedRelationships(): array
    {
        $rows = [];
        foreach ($this->database->table(self::INSTANCES)->orderBy('id')->get(['relationship_type', 'status']) as $row) {
            if (is_string($row->relationship_type) && is_string($row->status)) {
                $rows[] = ['type' => $row->relationship_type, 'status' => $row->status];
            }
        }

        return $rows;
    }

    public function storedFieldValues(): array
    {
        $rows = [];
        foreach ($this->database->table(self::FIELDS.' as v')
            ->join(self::INSTANCES.' as r', 'r.id', '=', 'v.relationship_id')
            ->orderBy('v.relationship_id')->orderBy('v.field_key')
            ->get(['r.relationship_type as type', 'v.field_key as field', 'v.value as value']) as $row) {
            if (is_string($row->type) && is_string($row->field) && is_string($row->value)) {
                $rows[] = ['type' => $row->type, 'field' => $row->field, 'value' => $row->value];
            }
        }

        return $rows;
    }

    private function hydrate(stdClass $row, bool $lock): RelationshipRecord
    {
        assert(is_string($row->id) && is_string($row->person_id) && is_string($row->relationship_type) && is_string($row->status));
        assert(is_numeric($row->revision) && is_string($row->status_changed_at) && is_string($row->status_changed_by));
        assert(is_string($row->created_at) && is_string($row->created_by) && is_string($row->updated_at) && is_string($row->updated_by));

        $id = $row->id;
        $fieldsQuery = $this->database->table(self::FIELDS)->where('relationship_id', $id)->orderBy('field_key');
        $historyQuery = $this->database->table(self::HISTORY)->where('relationship_id', $id)->orderBy('changed_at')->orderBy('id');
        $decisions = $this->database->table(self::DECISIONS)->where('relationship_id', $id)->orderBy('role_key');
        if ($lock) {
            $fieldsQuery->lockForUpdate();
            $historyQuery->lockForUpdate();
            $decisions->lockForUpdate();
        }
        $decisions->pluck('role_key');

        $fields = [];
        foreach ($fieldsQuery->get(['field_key', 'value']) as $field) {
            if (is_string($field->field_key) && is_string($field->value)) {
                $fields[$field->field_key] = $field->value;
            }
        }
        $history = [];
        foreach ($historyQuery->get(['from_status', 'to_status', 'changed_at', 'changed_by']) as $change) {
            assert(($change->from_status === null || is_string($change->from_status)) && is_string($change->to_status));
            assert(is_string($change->changed_at) && is_string($change->changed_by));
            $history[] = new StatusChange(
                $change->from_status,
                $change->to_status,
                SqlTime::from($change->changed_at),
                PersonId::fromString($change->changed_by),
            );
        }

        return new RelationshipRecord(
            RelationshipId::fromString($id),
            PersonId::fromString($row->person_id),
            $row->relationship_type,
            $row->status,
            (int) $row->revision,
            SqlTime::from($row->status_changed_at),
            PersonId::fromString($row->status_changed_by),
            SqlTime::from($row->created_at),
            PersonId::fromString($row->created_by),
            SqlTime::from($row->updated_at),
            PersonId::fromString($row->updated_by),
            $fields,
            $history,
        );
    }

    private function insertChange(RelationshipId $id, StatusChange $change): void
    {
        $this->database->table(self::HISTORY)->insert([
            'id' => strtolower((string) Str::ulid()),
            'relationship_id' => $id->value,
            'from_status' => $change->fromStatus,
            'to_status' => $change->toStatus,
            'changed_at' => SqlTime::to($change->changedAt),
            'changed_by' => $change->changedBy->value,
        ]);
    }
}
