<?php

declare(strict_types=1);

namespace App\Modules\Crm\Infrastructure;

use App\Modules\Crm\Domain\Interaction;
use App\Modules\Crm\Domain\InteractionId;
use App\Modules\Crm\Domain\InteractionKind;
use App\Modules\Crm\Domain\InteractionRepository;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;
use stdClass;

final readonly class DatabaseInteractionRepository implements InteractionRepository
{
    private const string TABLE = 'contact_interactions';

    public function __construct(private ConnectionInterface $database) {}

    public function find(InteractionId $id): ?Interaction
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function page(PersonId $personId, int $page, int $perPage): array
    {
        $rows = $this->database->table(self::TABLE)
            ->where('person_id', $personId->value)
            ->orderByDesc('occurred_at')->orderByDesc('created_at')->orderByDesc('id')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get()->all();

        return array_map(self::toDomain(...), array_values($rows));
    }

    public function count(PersonId $personId): int
    {
        return $this->database->table(self::TABLE)->where('person_id', $personId->value)->count();
    }

    public function add(Interaction $interaction): void
    {
        $this->database->table(self::TABLE)->insert([
            'id' => $interaction->id->value,
            'person_id' => $interaction->personId->value,
            'kind' => $interaction->kind->value,
            'body' => $interaction->body,
            'occurred_at' => SqlTime::to($interaction->occurredAt),
            'author_person_id' => $interaction->authorPersonId->value,
            'updated_by_person_id' => $interaction->updatedByPersonId?->value,
            'created_at' => SqlTime::to($interaction->createdAt),
            'updated_at' => SqlTime::to($interaction->updatedAt),
        ]);
    }

    public function save(Interaction $interaction, array $fields): void
    {
        $columns = [];
        foreach ($fields as $field) {
            $columns[$field] = match ($field) {
                'kind' => $interaction->kind->value,
                'body' => $interaction->body,
                'occurred_at' => SqlTime::to($interaction->occurredAt),
            };
        }

        $this->database->table(self::TABLE)
            ->where('id', $interaction->id->value)->where('person_id', $interaction->personId->value)
            ->update([
                ...$columns,
                'updated_by_person_id' => $interaction->updatedByPersonId?->value,
                'updated_at' => SqlTime::to($interaction->updatedAt),
            ]);
    }

    public function remove(PersonId $personId, InteractionId $id): bool
    {
        return $this->database->table(self::TABLE)
            ->where('id', $id->value)->where('person_id', $personId->value)
            ->delete() > 0;
    }

    private static function toDomain(stdClass $row): Interaction
    {
        assert(is_string($row->id) && is_string($row->person_id) && is_string($row->kind) && is_string($row->body));
        assert(is_string($row->occurred_at) && is_string($row->author_person_id) && is_string($row->created_at) && is_string($row->updated_at));
        assert($row->updated_by_person_id === null || is_string($row->updated_by_person_id));

        return Interaction::reconstitute(
            InteractionId::fromString($row->id),
            PersonId::fromString($row->person_id),
            InteractionKind::from($row->kind),
            $row->body,
            SqlTime::from($row->occurred_at),
            PersonId::fromString($row->author_person_id),
            $row->updated_by_person_id === null ? null : PersonId::fromString($row->updated_by_person_id),
            SqlTime::from($row->created_at),
            SqlTime::from($row->updated_at),
        );
    }
}
