<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * One thing a Guardian wrote in a discussion: the opening message (sequence 1) or a reply (ADR 0035). One model for all of
 * them, so authorship, editing and removal are one set of rules.
 *
 * - The ORIGINAL author never changes. `editedByPersonId` is who last changed the text; in Phase 1 it always equals the
 *   author, and is kept because it is the seam a later rule could use without rewriting what authorship means.
 * - Removal is a tombstone: the row, its place and its author remain; `body` is NULL and `removedAt` is set. The text is
 *   not kept anywhere else. A removed message is never given text again.
 */
final readonly class DiscussionMessage
{
    private function __construct(
        public DiscussionMessageId $id,
        public DiscussionId $discussionId,
        public int $sequence,
        public PersonId $authorPersonId,
        public ?string $body,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $editedAt,
        public ?PersonId $editedByPersonId,
        public ?DateTimeImmutable $removedAt,
    ) {}

    /** @throws InvalidDiscussionInput */
    public static function post(DiscussionMessageId $id, DiscussionId $discussionId, int $sequence, PersonId $author, string $body, DateTimeImmutable $now): self
    {
        return new self($id, $discussionId, $sequence, $author, MessageBody::normalise($body), $now, null, null, null);
    }

    public static function reconstitute(
        DiscussionMessageId $id,
        DiscussionId $discussionId,
        int $sequence,
        PersonId $authorPersonId,
        ?string $body,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $editedAt,
        ?PersonId $editedByPersonId,
        ?DateTimeImmutable $removedAt,
    ): self {
        return new self($id, $discussionId, $sequence, $authorPersonId, $body, $createdAt, $editedAt, $editedByPersonId, $removedAt);
    }

    public function isRemoved(): bool
    {
        return $this->removedAt !== null;
    }

    public function isWrittenBy(PersonId $person): bool
    {
        return $this->authorPersonId->equals($person);
    }

    /**
     * The message with its text changed by `$by`. A change that leaves the normalised text as it was returns the message
     * untouched, so nothing is written and it is not marked edited.
     *
     * @throws InvalidDiscussionInput
     */
    public function edited(string $body, PersonId $by, DateTimeImmutable $now): self
    {
        $body = MessageBody::normalise($body);
        if ($body === $this->body) {
            return $this;
        }

        return new self($this->id, $this->discussionId, $this->sequence, $this->authorPersonId, $body, $this->createdAt, $now, $by, $this->removedAt);
    }
}
