<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Infrastructure;

use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessage;
use App\Modules\Discussions\Domain\DiscussionMessageId;
use App\Modules\Discussions\Domain\DiscussionMessageRepository;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;

final readonly class DatabaseDiscussionMessageRepository implements DiscussionMessageRepository
{
    private const string TABLE = 'discussion_messages';

    public function __construct(private ConnectionInterface $database) {}

    public function find(DiscussionMessageId $id): ?DiscussionMessage
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function page(DiscussionId $discussionId, int $page, int $perPage): array
    {
        $rows = $this->database->table(self::TABLE)
            ->where('discussion_id', $discussionId->value)
            ->orderBy('sequence')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get()->all();

        return array_map(self::toDomain(...), array_values($rows));
    }

    public function count(DiscussionId $discussionId): int
    {
        return $this->database->table(self::TABLE)->where('discussion_id', $discussionId->value)->count();
    }

    public function add(DiscussionMessage $message): void
    {
        $this->database->table(self::TABLE)->insert([
            'id' => $message->id->value,
            'discussion_id' => $message->discussionId->value,
            'sequence' => $message->sequence,
            'author_person_id' => $message->authorPersonId->value,
            'body' => $message->body,
            'created_at' => SqlTime::to($message->createdAt),
            'edited_at' => $message->editedAt === null ? null : SqlTime::to($message->editedAt),
            'edited_by_person_id' => $message->editedByPersonId?->value,
            'removed_at' => $message->removedAt === null ? null : SqlTime::to($message->removedAt),
        ]);
    }

    public function openingAuthors(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $authors = [];
        $rows = $this->database->table(self::TABLE)
            ->whereIn('discussion_id', array_map(static fn (DiscussionId $id): string => $id->value, $ids))
            ->where('sequence', 1)
            ->get(['discussion_id', 'author_person_id']);
        foreach ($rows as $row) {
            assert(is_string($row->discussion_id) && is_string($row->author_person_id));
            $authors[$row->discussion_id] = PersonId::fromString($row->author_person_id);
        }

        return $authors;
    }

    public function saveEdit(DiscussionMessage $message): void
    {
        assert($message->body !== null && $message->editedAt !== null && $message->editedByPersonId !== null);

        // Conditional: an edit that lost a race with a removal changes nothing, and never gives a tombstone text again.
        $this->database->table(self::TABLE)
            ->where('id', $message->id->value)
            ->where('author_person_id', $message->authorPersonId->value)
            ->whereNull('removed_at')
            ->update([
                'body' => $message->body,
                'edited_at' => SqlTime::to($message->editedAt),
                'edited_by_person_id' => $message->editedByPersonId->value,
            ]);
    }

    public function saveRemoval(DiscussionMessageId $id, DateTimeImmutable $now): void
    {
        $this->database->table(self::TABLE)
            ->where('id', $id->value)
            ->whereNull('removed_at')
            ->update(['body' => null, 'removed_at' => SqlTime::to($now)]);
    }

    private static function toDomain(stdClass $row): DiscussionMessage
    {
        assert(is_string($row->id) && is_string($row->discussion_id) && is_numeric($row->sequence) && is_string($row->author_person_id));
        assert($row->body === null || is_string($row->body));
        assert(is_string($row->created_at));
        assert($row->edited_at === null || is_string($row->edited_at));
        assert($row->edited_by_person_id === null || is_string($row->edited_by_person_id));
        assert($row->removed_at === null || is_string($row->removed_at));

        return DiscussionMessage::reconstitute(
            DiscussionMessageId::fromString($row->id),
            DiscussionId::fromString($row->discussion_id),
            (int) $row->sequence,
            PersonId::fromString($row->author_person_id),
            $row->body,
            SqlTime::from($row->created_at),
            $row->edited_at === null ? null : SqlTime::from($row->edited_at),
            $row->edited_by_person_id === null ? null : PersonId::fromString($row->edited_by_person_id),
            $row->removed_at === null ? null : SqlTime::from($row->removed_at),
        );
    }
}
