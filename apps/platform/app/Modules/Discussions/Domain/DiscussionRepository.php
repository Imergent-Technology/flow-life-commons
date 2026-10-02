<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Domain;

/**
 * Persistence for the discussion header. Each write names the columns it changes and no others, so a title correction
 * (which takes no lock) can never overwrite a count a concurrent reply moved.
 */
interface DiscussionRepository
{
    public function find(DiscussionId $id): ?Discussion;

    /**
     * Reads the row and holds it until the surrounding transaction ends (`SELECT ... FOR UPDATE`). Reply, resolve and
     * reopen take it, which is what serialises them. Only meaningful inside a transaction.
     */
    public function lock(DiscussionId $id): ?Discussion;

    /**
     * Most recently active first: last activity descending, then id descending, a total order.
     *
     * @return list<Discussion>
     */
    public function page(?DiscussionState $state, ?string $titleContains, int $page, int $perPage): array;

    public function count(?DiscussionState $state, ?string $titleContains): int;

    public function add(Discussion $discussion): void;

    /** Writes the count, the activity time and `updated_at`. */
    public function savePosted(Discussion $discussion): void;

    /** Writes the title and `updated_at`. */
    public function saveTitle(Discussion $discussion): void;

    /** Writes the state, who resolved it and when, and `updated_at`. */
    public function saveState(Discussion $discussion): void;
}
