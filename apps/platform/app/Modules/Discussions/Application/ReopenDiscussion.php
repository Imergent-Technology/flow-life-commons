<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Discussions\Domain\Discussion;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Reopens a resolved discussion, clearing who resolved it and when. Needs `discussions.participate`: any participant may,
 * since reopening destroys nothing and is undone by resolving again. Takes the discussion row's lock, so it is serialised
 * against replies (see ReplyToDiscussion). Idempotent: reopening an open discussion changes nothing. Not activity.
 */
final readonly class ReopenDiscussion
{
    public function __construct(
        private AuthorizeAction $authorize,
        private DiscussionRepository $discussions,
        private DiscussionViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws DiscussionNotFound
     */
    public function __invoke(Actor $actor, DiscussionId $id): DiscussionView
    {
        ($this->authorize)($actor, Capability::ParticipateInDiscussions);

        $discussion = $this->database->transaction(function () use ($id): Discussion {
            $locked = $this->discussions->lock($id) ?? throw new DiscussionNotFound;
            $reopened = $locked->reopened(DateTimeImmutable::createFromInterface(now()));
            if ($reopened !== $locked) {
                $this->discussions->saveState($reopened);
            }

            return $reopened;
        }, 3);

        return $this->views->ofDiscussions([$discussion])[0];
    }
}
