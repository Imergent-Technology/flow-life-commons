<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessage;
use App\Modules\Discussions\Domain\DiscussionMessageId;
use App\Modules\Discussions\Domain\DiscussionMessageRepository;
use App\Modules\Discussions\Domain\DiscussionRepository;
use App\Modules\Discussions\Domain\InvalidDiscussionInput;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Adds a reply to an OPEN discussion. Needs `discussions.participate`.
 *
 * This is the one place a lock is the point (ADR 0035, decision 22). The discussion row is locked, THEN its state is read,
 * so a reply and a resolution are serialised: whichever commits first wins, and a reply that queued behind a resolution is
 * refused rather than committed into a resolved discussion. The same lock allocates the next sequence (the count under the
 * lock plus one), so sequences are gap-free in commit order; `unique(discussion_id, sequence)` is the backstop.
 *
 * Checks, in order: the capability, that the discussion exists, that it is open, then the text.
 */
final readonly class ReplyToDiscussion
{
    public function __construct(
        private AuthorizeAction $authorize,
        private DiscussionRepository $discussions,
        private DiscussionMessageRepository $messages,
        private DiscussionViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws DiscussionNotFound
     * @throws DiscussionResolved
     * @throws InvalidDiscussionInput
     */
    public function __invoke(Actor $actor, DiscussionId $id, string $body): MessageView
    {
        ($this->authorize)($actor, Capability::ParticipateInDiscussions);

        $message = $this->database->transaction(function () use ($actor, $id, $body): DiscussionMessage {
            $discussion = $this->discussions->lock($id) ?? throw new DiscussionNotFound;
            if ($discussion->isResolved()) {
                throw new DiscussionResolved;
            }

            $now = DateTimeImmutable::createFromInterface(now());
            $message = DiscussionMessage::post(DiscussionMessageId::generate(), $id, $discussion->nextSequence(), $actor->personId, $body, $now);

            $this->messages->add($message);
            $this->discussions->savePosted($discussion->posted($now));

            return $message;
        }, 3);

        return $this->views->ofMessages([$message])[0];
    }
}
