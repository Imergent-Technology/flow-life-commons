<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * A topic Guardians talk about (ADR 0035). It is the thread's header: the title, whether it is open, and the counters that
 * order a list. Every word anyone wrote is a DiscussionMessage, including the opening one; the creator is that message's
 * author and is not stored here a second time.
 *
 * `messageCount` is the last allocated sequence and counts tombstones. `lastActivityAt` is when a message was last
 * POSTED; an edit, a removal, a new title, resolving and reopening are not activity.
 */
final readonly class Discussion
{
    private function __construct(
        public DiscussionId $id,
        public string $title,
        public DiscussionState $state,
        public int $messageCount,
        public DateTimeImmutable $lastActivityAt,
        public ?DateTimeImmutable $resolvedAt,
        public ?PersonId $resolvedByPersonId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * A new, open discussion that already holds its opening message (so the count is 1).
     *
     * @throws InvalidDiscussionInput
     */
    public static function start(DiscussionId $id, string $title, DateTimeImmutable $now): self
    {
        return new self($id, DiscussionTitle::normalise($title), DiscussionState::Open, 1, $now, null, null, $now, $now);
    }

    public static function reconstitute(
        DiscussionId $id,
        string $title,
        DiscussionState $state,
        int $messageCount,
        DateTimeImmutable $lastActivityAt,
        ?DateTimeImmutable $resolvedAt,
        ?PersonId $resolvedByPersonId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $title, $state, $messageCount, $lastActivityAt, $resolvedAt, $resolvedByPersonId, $createdAt, $updatedAt);
    }

    public function isResolved(): bool
    {
        return $this->state === DiscussionState::Resolved;
    }

    /** The sequence the next message takes. Only meaningful while the discussion row is locked. */
    public function nextSequence(): int
    {
        return $this->messageCount + 1;
    }

    /** A message was posted: the count and the activity time move, and nothing else does. */
    public function posted(DateTimeImmutable $now): self
    {
        return new self($this->id, $this->title, $this->state, $this->messageCount + 1, $now, $this->resolvedAt, $this->resolvedByPersonId, $this->createdAt, $now);
    }

    /**
     * Not activity: the last-activity time is untouched.
     *
     * @throws InvalidDiscussionInput
     */
    public function retitled(string $title, DateTimeImmutable $now): self
    {
        return new self($this->id, DiscussionTitle::normalise($title), $this->state, $this->messageCount, $this->lastActivityAt, $this->resolvedAt, $this->resolvedByPersonId, $this->createdAt, $now);
    }

    /** Resolving a resolved discussion changes nothing: the original resolver and time are kept. */
    public function resolvedBy(PersonId $by, DateTimeImmutable $now): self
    {
        if ($this->isResolved()) {
            return $this;
        }

        return new self($this->id, $this->title, DiscussionState::Resolved, $this->messageCount, $this->lastActivityAt, $now, $by, $this->createdAt, $now);
    }

    /** Reopening clears the resolution. Reopening an open discussion changes nothing. */
    public function reopened(DateTimeImmutable $now): self
    {
        if (! $this->isResolved()) {
            return $this;
        }

        return new self($this->id, $this->title, DiscussionState::Open, $this->messageCount, $this->lastActivityAt, null, null, $this->createdAt, $now);
    }
}
