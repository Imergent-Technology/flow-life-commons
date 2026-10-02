<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Discussions\Domain\DiscussionRepository;
use App\Modules\Discussions\Domain\DiscussionState;
use App\Shared\Domain\Actor;

/**
 * Discussions, most recently active first (last activity, then id: a total order). Optionally one state, and optionally a
 * case-insensitive match on the TITLE only: message text is not searched. Needs `discussions.view`.
 */
final readonly class PageDiscussions
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private AuthorizeAction $authorize,
        private DiscussionRepository $discussions,
        private DiscussionViews $views,
    ) {}

    /** @throws AccessDenied */
    public function __invoke(Actor $actor, ?DiscussionState $state, ?string $titleContains, int $page, int $perPage): DiscussionPage
    {
        ($this->authorize)($actor, Capability::ViewDiscussions);

        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
        $titleContains = $titleContains === null ? null : trim($titleContains);

        return new DiscussionPage(
            $this->views->ofDiscussions($this->discussions->page($state, $titleContains, $page, $perPage)),
            $page,
            $perPage,
            $this->discussions->count($state, $titleContains),
        );
    }
}
