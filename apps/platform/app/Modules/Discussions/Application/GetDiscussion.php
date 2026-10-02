<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionRepository;
use App\Shared\Domain\Actor;

/** One discussion's header, open or resolved. Needs `discussions.view`. */
final readonly class GetDiscussion
{
    public function __construct(
        private AuthorizeAction $authorize,
        private DiscussionRepository $discussions,
        private DiscussionViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws DiscussionNotFound
     */
    public function __invoke(Actor $actor, DiscussionId $id): DiscussionView
    {
        ($this->authorize)($actor, Capability::ViewDiscussions);

        $discussion = $this->discussions->find($id) ?? throw new DiscussionNotFound;

        return $this->views->ofDiscussions([$discussion])[0];
    }
}
