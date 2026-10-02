<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessageRepository;
use App\Modules\Discussions\Domain\DiscussionRepository;
use App\Shared\Domain\Actor;

/** A discussion's messages in sequence order, oldest first, tombstones in their place. Needs `discussions.view`. */
final readonly class PageDiscussionMessages
{
    public const int MAX_PER_PAGE = PageDiscussions::MAX_PER_PAGE;

    public function __construct(
        private AuthorizeAction $authorize,
        private DiscussionRepository $discussions,
        private DiscussionMessageRepository $messages,
        private DiscussionViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws DiscussionNotFound
     */
    public function __invoke(Actor $actor, DiscussionId $id, int $page, int $perPage): MessagePage
    {
        ($this->authorize)($actor, Capability::ViewDiscussions);

        if ($this->discussions->find($id) === null) {
            throw new DiscussionNotFound;
        }

        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));

        return new MessagePage(
            $this->views->ofMessages($this->messages->page($id, $page, $perPage)),
            $page,
            $perPage,
            $this->messages->count($id),
        );
    }
}
