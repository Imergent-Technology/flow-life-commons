<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure;

use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Query builder, like Crm's and Discussions': no model whose save() could write outside the paths provided.
 */
final readonly class DatabaseCategoryRepository implements CategoryRepository
{
    private const string TABLE = 'resource_categories';

    public function __construct(private ConnectionInterface $database) {}

    public function find(CategoryId $id): ?Category
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function lock(CategoryId $id): ?Category
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->lockForUpdate()->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function lockAll(): array
    {
        // Id order, always: two transactions that each lock the set meet the rows in the same order and cannot deadlock.
        $this->database->table(self::TABLE)->orderBy('id')->lockForUpdate()->pluck('id');

        // The set is READ in a second statement, on purpose. On PostgreSQL a statement's snapshot is taken before it waits for a lock,
        // so a Category committed by the transaction it waited for would be missing from a single lock-and-read statement, and a
        // reorder of the old list would pass where MariaDB (whose locking read sees the latest rows) refuses it. A fresh statement sees
        // what committed while this one waited, on both engines, so both decide on the same set (ADR 0037, decision 57).
        return $this->all();
    }

    public function all(): array
    {
        $rows = $this->database->table(self::TABLE)->orderBy('position')->orderBy('id')->get()->all();

        return array_map(self::toDomain(...), array_values($rows));
    }

    public function findByCanonicalName(string $canonical): ?Category
    {
        $row = $this->database->table(self::TABLE)->where('name_canonical', $canonical)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function nextPosition(): int
    {
        $max = $this->database->table(self::TABLE)->max('position');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    public function add(Category $category): void
    {
        $this->database->table(self::TABLE)->insert([
            'id' => $category->id->value,
            'name' => $category->name,
            'name_canonical' => $category->nameCanonical,
            'position' => $category->position,
            'created_by_person_id' => $category->provenance->createdBy->value,
            'updated_by_person_id' => $category->provenance->updatedBy->value,
            'created_at' => SqlTime::to($category->provenance->createdAt),
            'updated_at' => SqlTime::to($category->provenance->updatedAt),
        ]);
    }

    public function saveName(Category $category): void
    {
        $this->database->table(self::TABLE)->where('id', $category->id->value)->update([
            'name' => $category->name,
            'name_canonical' => $category->nameCanonical,
            'updated_by_person_id' => $category->provenance->updatedBy->value,
            'updated_at' => SqlTime::to($category->provenance->updatedAt),
        ]);
    }

    public function savePosition(CategoryId $id, int $position): void
    {
        $this->database->table(self::TABLE)->where('id', $id->value)->update(['position' => $position]);
    }

    public function delete(CategoryId $id): void
    {
        $this->database->table(self::TABLE)->where('id', $id->value)->delete();
    }

    private static function toDomain(stdClass $row): Category
    {
        return Category::reconstitute(
            CategoryId::fromString(Rows::string($row, 'id')),
            Rows::string($row, 'name'),
            Rows::string($row, 'name_canonical'),
            Rows::int($row, 'position'),
            Rows::provenance($row),
        );
    }
}
