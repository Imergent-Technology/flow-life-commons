<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessageId;
use App\Modules\Discussions\Domain\DiscussionMessageRepository;
use App\Modules\Discussions\Domain\InvalidDiscussionInput;
use App\Shared\Domain\Actor;
use DateTimeImmutable;

/**
 * Edits the text of the caller's OWN message, in a discussion that may be open or resolved. Needs
 * `discussions.participate` AND authorship: the capability never means changing someone else's words (ADR 0035).
 *
 * Checks, in order: the capability, that the message exists in that discussion, that the caller wrote it, that it has not been
 * removed, then the text. No lock: the write is conditional on the row not being removed, so an edit that loses a race with a
 * removal changes nothing and reports the message as removed, and one that wins is simply followed by the removal. It does
 * not touch the discussion, so it is not activity.
 */
final readonly class EditOwnMessage
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
     * @throws MessageRemoved
     * @throws InvalidDiscussionInput
     */
    public function __invoke(Actor $actor, DiscussionId $discussionId, DiscussionMessageId $id, string $body): MessageView
    {
        ($this->authorize)($actor, Capability::ParticipateInDiscussions);

        $message = $this->messages->find($id);
        if ($message === null || ! $message->discussionId->equals($discussionId)) {
            throw new MessageNotFound;
        }
        if (! $message->isWrittenBy($actor->personId)) {
            throw new NotAuthor;
        }
        if ($message->isRemoved()) {
            throw new MessageRemoved;
        }

        $edited = $message->edited($body, $actor->personId, DateTimeImmutable::createFromInterface(now()));
        if ($edited !== $message) {
            $this->messages->saveEdit($edited);
        }

        // Re-read rather than trusting an affected-row count (MariaDB reports an unchanged row as 0): a message removed
        // between the check and the write is reported as removed, never returned as though the edit had landed.
        $current = $this->messages->find($id) ?? throw new MessageNotFound;
        if ($current->isRemoved()) {
            throw new MessageRemoved;
        }

        return $this->views->ofMessages([$current])[0];
    }
}
