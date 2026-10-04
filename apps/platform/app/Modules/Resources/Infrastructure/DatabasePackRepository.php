<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure;

use App\Modules\Resources\Domain\AudienceSet;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\ManagedPackFilter;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\PublicationState;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * Query builder, no model. Every write names its columns: a publication change writes the state and provenance, an authored
 * edit writes the authored fields conditionally on the revision, and neither can overwrite the other's columns.
 */
final readonly class DatabasePackRepository implements PackRepository
{
    private const string TABLE = 'resource_packs';

    private const string AUDIENCES = 'resource_pack_audiences';

    public function __construct(private ConnectionInterface $database) {}

    public function find(PackId $id): ?Pack
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    public function lock(PackId $id): ?Pack
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->lockForUpdate()->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    public function add(Pack $pack): void
    {
        $this->database->table(self::TABLE)->insert([
            'id' => $pack->id->value,
            'category_id' => $pack->categoryId?->value,
            'position' => $pack->position,
            'title' => $pack->title,
            'summary' => $pack->summary,
            'is_series' => $pack->isSeries,
            'state' => $pack->state->value,
            'revision' => $pack->revision,
            'created_by_person_id' => $pack->provenance->createdBy->value,
            'updated_by_person_id' => $pack->provenance->updatedBy->value,
            'created_at' => SqlTime::to($pack->provenance->createdAt),
            'updated_at' => SqlTime::to($pack->provenance->updatedAt),
        ]);
        $this->insertAudiences($pack);
    }

    public function saveAuthored(Pack $pack, int $expectedRevision): bool
    {
        // The revision moves on every authored edit, so the row always changes and the engine always reports one affected row
        // on success (MariaDB reports an unchanged row as zero, which cannot happen here).
        $affected = $this->database->table(self::TABLE)
            ->where('id', $pack->id->value)
            ->where('revision', $expectedRevision)
            ->update([
                'category_id' => $pack->categoryId?->value,
                'position' => $pack->position,
                'title' => $pack->title,
                'summary' => $pack->summary,
                'is_series' => $pack->isSeries,
                'revision' => $pack->revision,
                'updated_by_person_id' => $pack->provenance->updatedBy->value,
                'updated_at' => SqlTime::to($pack->provenance->updatedAt),
            ]);

        return $affected === 1;
    }

    public function saveState(Pack $pack): void
    {
        $this->database->table(self::TABLE)->where('id', $pack->id->value)->update([
            'state' => $pack->state->value,
            'updated_by_person_id' => $pack->provenance->updatedBy->value,
            'updated_at' => SqlTime::to($pack->provenance->updatedAt),
        ]);
    }

    public function saveAudiences(Pack $pack): void
    {
        $this->database->table(self::AUDIENCES)->where('pack_id', $pack->id->value)->delete();
        $this->insertAudiences($pack);
        $this->database->table(self::TABLE)->where('id', $pack->id->value)->update([
            'updated_by_person_id' => $pack->provenance->updatedBy->value,
            'updated_at' => SqlTime::to($pack->provenance->updatedAt),
        ]);
    }

    public function savePosition(PackId $id, int $position): void
    {
        $this->database->table(self::TABLE)->where('id', $id->value)->update(['position' => $position]);
    }

    public function delete(PackId $id): void
    {
        $this->database->table(self::AUDIENCES)->where('pack_id', $id->value)->delete();
        $this->database->table(self::TABLE)->where('id', $id->value)->delete();
    }

    public function idsIn(?CategoryId $category): array
    {
        $query = $this->database->table(self::TABLE);
        $category === null ? $query->whereNull('category_id') : $query->where('category_id', $category->value);
        $ids = [];
        foreach ($query->orderBy('position')->orderBy('id')->pluck('id') as $id) {
            assert(is_string($id));
            $ids[] = PackId::fromString($id);
        }

        return $ids;
    }

    public function countIn(CategoryId $category): int
    {
        return $this->database->table(self::TABLE)->where('category_id', $category->value)->count();
    }

    public function nextPositionIn(?CategoryId $category): int
    {
        $query = $this->database->table(self::TABLE);
        $category === null ? $query->whereNull('category_id') : $query->where('category_id', $category->value);
        $max = $query->max('position');

        return (is_numeric($max) ? (int) $max : 0) + 1;
    }

    public function page(ManagedPackFilter $filter, int $page, int $perPage): array
    {
        $rows = $this->filtered($filter)
            ->select('p.*')
            // Packs with no Category last, portably: both engines order NULLs differently by default.
            ->orderByRaw('case when c.position is null then 1 else 0 end')
            ->orderBy('c.position')->orderBy('p.position')->orderBy('p.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get()->all();

        return $this->hydrate(array_values($rows));
    }

    public function count(ManagedPackFilter $filter): int
    {
        return $this->filtered($filter)->count();
    }

    public function published(?CategoryId $category): array
    {
        $query = $this->database->table(self::TABLE.' as p')
            ->join('resource_categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.state', PublicationState::Published->value);
        if ($category !== null) {
            $query->where('p.category_id', $category->value);
        }
        $rows = $query->select('p.*')->orderBy('c.position')->orderBy('p.position')->orderBy('p.id')->get()->all();

        return $this->hydrate(array_values($rows));
    }

    public function countsByCategory(array $categories): array
    {
        if ($categories === []) {
            return [];
        }
        $counts = [];
        $rows = $this->database->table(self::TABLE)
            ->selectRaw('category_id, count(*) as total')
            ->whereIn('category_id', array_map(static fn (CategoryId $c): string => $c->value, $categories))
            ->groupBy('category_id')
            ->get();
        foreach ($rows as $row) {
            $counts[Rows::string($row, 'category_id')] = Rows::int($row, 'total');
        }

        return $counts;
    }

    private function filtered(ManagedPackFilter $filter): Builder
    {
        $query = $this->database->table(self::TABLE.' as p')->leftJoin('resource_categories as c', 'c.id', '=', 'p.category_id');
        if ($filter->category !== null) {
            $query->where('p.category_id', $filter->category->value);
        }
        if ($filter->state !== null) {
            $query->where('p.state', $filter->state->value);
        }
        if ($filter->audience !== null) {
            $audience = $filter->audience->value;
            $query->whereExists(static function (Builder $exists) use ($audience): void {
                $exists->selectRaw('1')->from(self::AUDIENCES.' as a')->whereColumn('a.pack_id', 'p.id')->where('a.audience', $audience);
            });
        }
        if ($filter->cardType !== null) {
            $type = $filter->cardType->value;
            $query->whereExists(static function (Builder $exists) use ($type): void {
                $exists->selectRaw('1')->from('resource_cards as k')->whereColumn('k.pack_id', 'p.id')->where('k.type', $type);
            });
        }
        if ($filter->titleContains !== null && $filter->titleContains !== '') {
            $query->whereRaw(LikeContains::predicate('p.title'), [LikeContains::pattern($filter->titleContains)]);
        }

        return $query;
    }

    private function insertAudiences(Pack $pack): void
    {
        $rows = array_map(static fn (string $audience): array => ['pack_id' => $pack->id->value, 'audience' => $audience], $pack->audiences->values());
        if ($rows !== []) {
            $this->database->table(self::AUDIENCES)->insert($rows);
        }
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<Pack>
     */
    private function hydrate(array $rows): array
    {
        $ids = array_map(static fn (stdClass $row): string => Rows::string($row, 'id'), $rows);
        $keys = [];
        if ($ids !== []) {
            foreach ($this->database->table(self::AUDIENCES)->whereIn('pack_id', $ids)->get() as $audience) {
                $keys[Rows::string($audience, 'pack_id')][] = Rows::string($audience, 'audience');
            }
        }

        return array_map(static function (stdClass $row) use ($keys): Pack {
            $id = Rows::string($row, 'id');
            $category = Rows::nullableString($row, 'category_id');

            return Pack::reconstitute(
                PackId::fromString($id),
                $category === null ? null : CategoryId::fromString($category),
                Rows::int($row, 'position'),
                Rows::string($row, 'title'),
                Rows::nullableString($row, 'summary'),
                Rows::bool($row, 'is_series'),
                isset($keys[$id]) ? Rows::audiences($keys[$id]) : AudienceSet::none(),
                PublicationState::from(Rows::string($row, 'state')),
                Rows::int($row, 'revision'),
                Rows::provenance($row),
            );
        }, $rows);
    }
}
