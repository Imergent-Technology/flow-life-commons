<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\PersonId;

final class EloquentPersonRepository implements PersonRepository
{
    public function save(Person $person): void
    {
        $record = PersonRecord::query()->find($person->id->value) ?? new PersonRecord;

        $record->id = $person->id->value;
        $record->display_name = $person->displayName;
        $record->created_at = Utc::toColumn($person->createdAt);
        $record->updated_at = Utc::toColumn($person->updatedAt);
        $record->save();
    }

    public function find(PersonId $id): ?Person
    {
        $record = PersonRecord::query()->find($id->value);

        return $record === null ? null : $this->toDomain($record);
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $values = array_map(static fn (PersonId $id): string => $id->value, $ids);

        return array_values(PersonRecord::query()->whereIn('id', $values)->get()
            ->map($this->toDomain(...))->all());
    }

    private function toDomain(PersonRecord $record): Person
    {
        return Person::reconstitute(
            PersonId::fromString($record->id),
            $record->display_name,
            Utc::fromColumn($record->created_at),
            Utc::fromColumn($record->updated_at),
        );
    }
}
