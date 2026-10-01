<?php

declare(strict_types=1);

namespace App\Modules\Crm\Infrastructure;

use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

final readonly class DatabaseContactMethodRepository implements ContactMethodRepository
{
    private const string TABLE = 'contact_methods';

    public function __construct(private ConnectionInterface $database) {}

    public function find(ContactMethodId $id): ?ContactMethod
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function forPerson(PersonId $personId): array
    {
        return $this->hydrate(array_values($this->database->table(self::TABLE)
            ->where('person_id', $personId->value)
            ->orderBy('kind')->orderBy('created_at')->orderBy('id')
            ->get()->all()));
    }

    public function forPeople(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $methods = $this->hydrate(array_values($this->database->table(self::TABLE)
            ->whereIn('person_id', array_map(static fn (PersonId $id): string => $id->value, $personIds))
            ->orderBy('person_id')->orderBy('kind')->orderBy('created_at')->orderBy('id')
            ->get()->all()));

        $byPerson = [];
        foreach ($methods as $method) {
            $byPerson[$method->personId->value][] = $method;
        }

        return $byPerson;
    }

    public function add(ContactMethod $method): void
    {
        $this->database->table(self::TABLE)->insert([
            'id' => $method->id->value,
            'person_id' => $method->personId->value,
            'kind' => $method->kind->value,
            'value' => $method->value,
            'search_value' => $method->searchValue,
            'label' => $method->label,
            'primary_kind' => $method->isPrimary ? $method->kind->value : null,
            'created_at' => SqlTime::to($method->createdAt),
            'updated_at' => SqlTime::to($method->updatedAt),
        ]);
    }

    public function save(ContactMethod $method): void
    {
        $this->database->table(self::TABLE)->where('id', $method->id->value)->update([
            'value' => $method->value,
            'search_value' => $method->searchValue,
            'label' => $method->label,
            'primary_kind' => $method->isPrimary ? $method->kind->value : null,
            'updated_at' => SqlTime::to($method->updatedAt),
        ]);
    }

    public function remove(ContactMethodId $id): void
    {
        $this->database->table(self::TABLE)->where('id', $id->value)->delete();
    }

    public function clearPrimary(PersonId $personId, ContactMethodKind $kind): void
    {
        $this->database->table(self::TABLE)
            ->where('person_id', $personId->value)->where('primary_kind', $kind->value)
            ->update(['primary_kind' => null]);
    }

    public function personIdsWithSearchValue(ContactMethodKind $kind, string $searchValue): array
    {
        return $this->personIds($this->database->table(self::TABLE)
            ->where('kind', $kind->value)->where('search_value', $searchValue)
            ->distinct()->orderBy('person_id')->pluck('person_id')->all());
    }

    public function personIdsMatching(string $text, int $limit): array
    {
        $emailLike = LikeContains::pattern($text);
        $digits = preg_replace('/[^0-9]/', '', $text) ?? '';

        $ids = $this->database->table(self::TABLE)
            ->where(function (Builder $match) use ($emailLike, $digits): void {
                $match->where(function (Builder $email) use ($emailLike): void {
                    $email->where('kind', ContactMethodKind::Email->value)
                        ->whereRaw(LikeContains::predicate('search_value'), [$emailLike]);
                });
                // A phone number is found by its digits, and only when there are enough to mean something.
                if (strlen($digits) >= 3) {
                    $match->orWhere(function (Builder $phone) use ($digits): void {
                        $phone->where('kind', ContactMethodKind::Phone->value)
                            ->whereRaw(LikeContains::predicate('search_value'), [LikeContains::pattern($digits)]);
                    });
                }
            })
            ->distinct()->orderBy('person_id')->limit($limit + 1)->pluck('person_id')->all();

        return $this->personIds($ids);
    }

    /**
     * @param  array<mixed>  $values
     * @return list<PersonId>
     */
    private function personIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            assert(is_string($value));
            $ids[] = PersonId::fromString($value);
        }

        return $ids;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<ContactMethod>
     */
    private function hydrate(array $rows): array
    {
        return array_map(self::toDomain(...), $rows);
    }

    private static function toDomain(stdClass $row): ContactMethod
    {
        assert(is_string($row->id) && is_string($row->person_id) && is_string($row->kind));
        assert(is_string($row->value) && is_string($row->search_value) && is_string($row->created_at) && is_string($row->updated_at));
        assert($row->label === null || is_string($row->label));
        assert($row->primary_kind === null || is_string($row->primary_kind));

        return ContactMethod::reconstitute(
            ContactMethodId::fromString($row->id),
            PersonId::fromString($row->person_id),
            ContactMethodKind::from($row->kind),
            $row->value,
            $row->search_value,
            $row->label,
            $row->primary_kind !== null,
            SqlTime::from($row->created_at),
            SqlTime::from($row->updated_at),
        );
    }
}
