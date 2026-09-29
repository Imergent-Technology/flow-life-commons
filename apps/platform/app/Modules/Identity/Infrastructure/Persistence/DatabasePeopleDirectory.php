<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\PeopleDirectory;
use App\Modules\Identity\Application\PeoplePage;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\PersonSummary;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Plain query builder over `people` ALONE: a read model, two statements per page (a count and the page) and none
 * per row. It reads no other table, so nothing about an Account can leak through it or be matched by it.
 *
 * Portable: `lower()` and an explicit LIKE escape (see LikeFragment), the order on `lower(display_name)` then `id`,
 * and id sets as ordinary bound `IN` lists. An explicitly empty restriction never reaches the database.
 */
final readonly class DatabasePeopleDirectory implements PeopleDirectory
{
    public function __construct(private ConnectionInterface $database) {}

    public function search(PeopleQuery $query): PeoplePage
    {
        if ($query->restrictToIds !== null && $query->restrictToIds === []) {
            return new PeoplePage([], $query->page, $query->perPage, 0);
        }

        $people = $this->database->table('people');
        $this->narrow($people, $query);

        $total = (clone $people)->count();
        $rows = $people
            ->orderByRaw('lower(display_name) asc')->orderBy('id')
            ->forPage($query->page, $query->perPage)
            ->get(['id', 'display_name']);

        $summaries = [];
        foreach ($rows as $row) {
            assert(is_string($row->id) && is_string($row->display_name));
            $summaries[] = new PersonSummary(PersonId::fromString($row->id), $row->display_name);
        }

        return new PeoplePage($summaries, $query->page, $query->perPage, $total);
    }

    private function narrow(Builder $people, PeopleQuery $query): void
    {
        $fragment = $query->text === null ? '' : trim($query->text);
        if ($fragment !== '') {
            $like = LikeFragment::contains($fragment);
            $included = self::values($query->includeIds ?? []);
            $people->where(function (Builder $match) use ($like, $included): void {
                $match->whereRaw(LikeFragment::predicate('display_name'), [$like]);
                if ($included !== []) {
                    $match->orWhereIn('id', $included);
                }
            });
        }

        if ($query->restrictToIds !== null) {
            $people->whereIn('id', self::values($query->restrictToIds));
        }
    }

    /**
     * @param  list<PersonId>  $ids
     * @return list<string> distinct
     */
    private static function values(array $ids): array
    {
        return array_values(array_unique(array_map(static fn (PersonId $id): string => $id->value, $ids)));
    }
}
