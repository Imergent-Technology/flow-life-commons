<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessageRepository;
use App\Modules\Discussions\Domain\DiscussionRepository;
use App\Modules\Discussions\Domain\InvalidDiscussionInput;
use App\Shared\Domain\Actor;
use DateTimeImmutable;

/**
 * Corrects the title of a discussion the caller STARTED (they wrote its opening message), open or resolved. Needs
 * `discussions.participate` AND being the creator. Not activity. No lock: it writes the title column alone, so it cannot
 * overwrite a count a concurrent reply moved.
 */
final readonly class RetitleOwnDiscussion
{
    public function __construct(
        private AuthorizeAction $authorize,
        private DiscussionRepository $discussions,
        private DiscussionMessageRepository $messages,
        private DiscussionViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws DiscussionNotFound
     * @throws NotAuthor
     * @throws InvalidDiscussionInput
     */
    public function __invoke(Actor $actor, DiscussionId $id, string $title): DiscussionView
    {
        ($this->authorize)($actor, Capability::ParticipateInDiscussions);

        $discussion = $this->discussions->find($id) ?? throw new DiscussionNotFound;

        $creator = $this->messages->openingAuthors([$id])[$id->value] ?? null;
        if ($creator === null || ! $creator->equals($actor->personId)) {
            throw new NotAuthor;
        }

        $retitled = $discussion->retitled($title, DateTimeImmutable::createFromInterface(now()));
        $this->discussions->saveTitle($retitled);

        return $this->views->ofDiscussions([$this->discussions->find($id) ?? throw new DiscussionNotFound])[0];
    }
}
