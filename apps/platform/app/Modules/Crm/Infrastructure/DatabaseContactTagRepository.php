<?php

declare(strict_types=1);

namespace App\Modules\Crm\Infrastructure;

use App\Modules\Crm\Domain\ContactTag;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Crm\Domain\ContactTagRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;

final readonly class DatabaseContactTagRepository implements ContactTagRepository
{
    private const string TAGS = 'contact_tags';

    private const string ASSIGNMENTS = 'contact_tag_assignments';

    public function __construct(private ConnectionInterface $database) {}

    public function find(ContactTagId $id): ?ContactTag
    {
        $row = $this->database->table(self::TAGS)->where('id', $id->value)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function findForUpdate(ContactTagId $id): ?ContactTag
    {
        $row = $this->database->table(self::TAGS)->where('id', $id->value)->lockForUpdate()->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function findByCanonical(string $canonical): ?ContactTag
    {
        $row = $this->database->table(self::TAGS)->where('name_canonical', $canonical)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function allWithCounts(): array
    {
        $counts = [];
        foreach ($this->database->table(self::ASSIGNMENTS)->groupBy('tag_id')->selectRaw('tag_id, count(*) as people')->get() as $row) {
            assert(is_string($row->tag_id) && is_numeric($row->people));
            $counts[$row->tag_id] = (int) $row->people;
        }

        $tags = [];
        foreach ($this->database->table(self::TAGS)->orderBy('name_canonical')->orderBy('id')->get() as $row) {
            $tag = self::toDomain($row);
            $tags[] = ['tag' => $tag, 'holders' => $counts[$tag->id->value] ?? 0];
        }

        return $tags;
    }

    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(array_map(self::toDomain(...), $this->database->table(self::TAGS)
            ->whereIn('id', array_map(static fn (ContactTagId $id): string => $id->value, $ids))
            ->orderBy('name_canonical')->orderBy('id')->get()->all()));
    }

    public function add(ContactTag $tag): void
    {
        $this->database->table(self::TAGS)->insert([
            'id' => $tag->id->value,
            'name' => $tag->name,
            'name_canonical' => $tag->canonical,
            'created_by_account_id' => $tag->createdBy?->value,
            'created_at' => SqlTime::to($tag->createdAt),
        ]);
    }

    public function save(ContactTag $tag): void
    {
        $this->database->table(self::TAGS)->where('id', $tag->id->value)->update([
            'name' => $tag->name,
            'name_canonical' => $tag->canonical,
        ]);
    }

    public function remove(ContactTagId $id): void
    {
        $this->database->table(self::TAGS)->where('id', $id->value)->delete();
    }

    public function assignmentCount(ContactTagId $id): int
    {
        return $this->database->table(self::ASSIGNMENTS)->where('tag_id', $id->value)->count();
    }

    public function tagIdsOf(PersonId $personId): array
    {
        $ids = [];
        foreach ($this->database->table(self::ASSIGNMENTS)->where('person_id', $personId->value)->orderBy('tag_id')->pluck('tag_id') as $value) {
            assert(is_string($value));
            $ids[] = ContactTagId::fromString($value);
        }

        return $ids;
    }

    public function forPeople(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $rows = $this->database->table(self::ASSIGNMENTS.' as a')
            ->join(self::TAGS.' as t', 't.id', '=', 'a.tag_id')
            ->whereIn('a.person_id', array_map(static fn (PersonId $id): string => $id->value, $personIds))
            ->orderBy('a.person_id')->orderBy('t.name_canonical')->orderBy('t.id')
            ->get(['a.person_id as person_id', 't.id', 't.name', 't.name_canonical', 't.created_by_account_id', 't.created_at']);

        $byPerson = [];
        foreach ($rows as $row) {
            assert(is_string($row->person_id));
            $byPerson[$row->person_id][] = self::toDomain($row);
        }

        return $byPerson;
    }

    public function assign(PersonId $personId, ContactTagId $tagId, ?AccountId $by, DateTimeImmutable $at): void
    {
        $this->database->table(self::ASSIGNMENTS)->insert([
            'person_id' => $personId->value,
            'tag_id' => $tagId->value,
            'assigned_by_account_id' => $by?->value,
            'assigned_at' => SqlTime::to($at),
        ]);
    }

    public function unassign(PersonId $personId, ContactTagId $tagId): void
    {
        $this->database->table(self::ASSIGNMENTS)->where('person_id', $personId->value)->where('tag_id', $tagId->value)->delete();
    }

    public function personIdsWithTag(ContactTagId $tagId, int $limit): array
    {
        $ids = [];
        foreach ($this->database->table(self::ASSIGNMENTS)->where('tag_id', $tagId->value)->orderBy('person_id')->limit($limit + 1)->pluck('person_id') as $value) {
            assert(is_string($value));
            $ids[] = PersonId::fromString($value);
        }

        return $ids;
    }

    private static function toDomain(stdClass $row): ContactTag
    {
        assert(is_string($row->id) && is_string($row->name) && is_string($row->name_canonical) && is_string($row->created_at));
        assert($row->created_by_account_id === null || is_string($row->created_by_account_id));

        return ContactTag::reconstitute(
            ContactTagId::fromString($row->id),
            $row->name,
            $row->name_canonical,
            $row->created_by_account_id === null ? null : AccountId::fromString($row->created_by_account_id),
            SqlTime::from($row->created_at),
        );
    }
}
