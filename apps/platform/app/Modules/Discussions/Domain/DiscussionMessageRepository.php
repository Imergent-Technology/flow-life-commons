<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/** Persistence for messages. An edit and a removal are conditional writes, which is what makes them safe without a lock. */
interface DiscussionMessageRepository
{
    public function find(DiscussionMessageId $id): ?DiscussionMessage;

    /**
     * Oldest first, by sequence.
     *
     * @return list<DiscussionMessage>
     */
    public function page(DiscussionId $discussionId, int $page, int $perPage): array;

    public function count(DiscussionId $discussionId): int;

    public function add(DiscussionMessage $message): void;

    /**
     * Who wrote each discussion's opening message, keyed by DiscussionId value: the creator. A discussion with no opening
     * message (never the case) is omitted.
     *
     * @param  list<DiscussionId>  $ids
     * @return array<string, PersonId>
     */
    public function openingAuthors(array $ids): array;

    /**
     * Writes the text and the edit provenance, but only while the row is not removed and is still this author's.
     * Returns nothing: callers re-read, because the row count differs by engine when a value is unchanged.
     */
    public function saveEdit(DiscussionMessage $message): void;

    /** Sets `removed_at` and nulls `body`, but only while the row is not already removed. Idempotent. */
    public function saveRemoval(DiscussionMessageId $id, DateTimeImmutable $now): void;
}
