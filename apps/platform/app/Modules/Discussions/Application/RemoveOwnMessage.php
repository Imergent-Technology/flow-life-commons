<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessageId;
use App\Modules\Discussions\Domain\DiscussionMessageRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;

/**
 * Removes the caller's OWN message, leaving a tombstone in its place: the row, its sequence and its author stay, and the
 * text is set to NULL in the same statement that sets `removed_at`. It is not kept anywhere else and cannot be restored.
 * Needs `discussions.participate` AND authorship; allowed in a resolved discussion, since removal is how an author withdraws
 * something. Removing a removed message succeeds and changes nothing. Not activity, and no lock: the write is conditional.
 */
final readonly class RemoveOwnMessage
{
    public function __construct(
        private AuthorizeAction $authorize,
        private DiscussionMessageRepository $messages,
        private DiscussionViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws MessageNotFound
     * @throws NotAuthor
     */
    public function __invoke(Actor $actor, DiscussionId $discussionId, DiscussionMessageId $id): MessageView
    {
        ($this->authorize)($actor, Capability::ParticipateInDiscussions);

        $message = $this->messages->find($id);
        if ($message === null || ! $message->discussionId->equals($discussionId)) {
            throw new MessageNotFound;
        }
        if (! $message->isWrittenBy($actor->personId)) {
            throw new NotAuthor;
        }

        if (! $message->isRemoved()) {
            $this->messages->saveRemoval($id, DateTimeImmutable::createFromInterface(now()));
        }

        $current = $this->messages->find($id) ?? throw new MessageNotFound;

        return $this->views->ofMessages([$current])[0];
    }
}
