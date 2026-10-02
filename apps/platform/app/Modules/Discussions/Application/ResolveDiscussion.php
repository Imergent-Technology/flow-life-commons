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
 * Marks a discussion resolved. Needs `discussions.participate`: any participant may, since resolving destroys nothing and
 * is undone by reopening. Takes the discussion row's lock, so it is serialised against replies (see ReplyToDiscussion).
 * Idempotent: resolving a resolved discussion changes nothing and keeps the original resolver and time. Not activity.
 */
final readonly class ResolveDiscussion
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

        $discussion = $this->database->transaction(function () use ($actor, $id): Discussion {
            $locked = $this->discussions->lock($id) ?? throw new DiscussionNotFound;
            $resolved = $locked->resolvedBy($actor->personId, DateTimeImmutable::createFromInterface(now()));
            if ($resolved !== $locked) {
                $this->discussions->saveState($resolved);
            }

            return $resolved;
        }, 3);

        return $this->views->ofDiscussions([$discussion])[0];
    }
}
