<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Infrastructure;

use App\Modules\Discussions\Domain\Discussion;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionRepository;
use App\Modules\Discussions\Domain\DiscussionState;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * Query builder, like Crm's: no model whose save() could write outside the paths provided. Every write names its columns.
 */
final readonly class DatabaseDiscussionRepository implements DiscussionRepository
{
    private const string TABLE = 'discussions';

    public function __construct(private ConnectionInterface $database) {}

    public function find(DiscussionId $id): ?Discussion
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function lock(DiscussionId $id): ?Discussion
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->lockForUpdate()->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function page(?DiscussionState $state, ?string $titleContains, int $page, int $perPage): array
    {
        $rows = $this->filtered($state, $titleContains)
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get()->all();

        return array_map(self::toDomain(...), array_values($rows));
    }

    public function count(?DiscussionState $state, ?string $titleContains): int
    {
        return $this->filtered($state, $titleContains)->count();
    }

    public function add(Discussion $discussion): void
    {
        $this->database->table(self::TABLE)->insert([
            'id' => $discussion->id->value,
            'title' => $discussion->title,
            'state' => $discussion->state->value,
            'message_count' => $discussion->messageCount,
            'last_activity_at' => SqlTime::to($discussion->lastActivityAt),
            'resolved_at' => $discussion->resolvedAt === null ? null : SqlTime::to($discussion->resolvedAt),
            'resolved_by_person_id' => $discussion->resolvedByPersonId?->value,
            'created_at' => SqlTime::to($discussion->createdAt),
            'updated_at' => SqlTime::to($discussion->updatedAt),
        ]);
    }

    public function savePosted(Discussion $discussion): void
    {
        $this->database->table(self::TABLE)->where('id', $discussion->id->value)->update([
            'message_count' => $discussion->messageCount,
            'last_activity_at' => SqlTime::to($discussion->lastActivityAt),
            'updated_at' => SqlTime::to($discussion->updatedAt),
        ]);
    }

    public function saveTitle(Discussion $discussion): void
    {
        $this->database->table(self::TABLE)->where('id', $discussion->id->value)->update([
            'title' => $discussion->title,
            'updated_at' => SqlTime::to($discussion->updatedAt),
        ]);
    }

    public function saveState(Discussion $discussion): void
    {
        $this->database->table(self::TABLE)->where('id', $discussion->id->value)->update([
            'state' => $discussion->state->value,
            'resolved_at' => $discussion->resolvedAt === null ? null : SqlTime::to($discussion->resolvedAt),
            'resolved_by_person_id' => $discussion->resolvedByPersonId?->value,
            'updated_at' => SqlTime::to($discussion->updatedAt),
        ]);
    }

    private function filtered(?DiscussionState $state, ?string $titleContains): Builder
    {
        $query = $this->database->table(self::TABLE);
        if ($state !== null) {
            $query->where('state', $state->value);
        }
        if ($titleContains !== null && $titleContains !== '') {
            $query->whereRaw(LikeContains::predicate('title'), [LikeContains::pattern($titleContains)]);
        }

        return $query;
    }

    private static function toDomain(stdClass $row): Discussion
    {
        assert(is_string($row->id) && is_string($row->title) && is_string($row->state) && is_numeric($row->message_count));
        assert(is_string($row->last_activity_at) && is_string($row->created_at) && is_string($row->updated_at));
        assert($row->resolved_at === null || is_string($row->resolved_at));
        assert($row->resolved_by_person_id === null || is_string($row->resolved_by_person_id));

        return Discussion::reconstitute(
            DiscussionId::fromString($row->id),
            $row->title,
            DiscussionState::from($row->state),
            (int) $row->message_count,
            SqlTime::from($row->last_activity_at),
            $row->resolved_at === null ? null : SqlTime::from($row->resolved_at),
            $row->resolved_by_person_id === null ? null : PersonId::fromString($row->resolved_by_person_id),
            SqlTime::from($row->created_at),
            SqlTime::from($row->updated_at),
        );
    }
}
