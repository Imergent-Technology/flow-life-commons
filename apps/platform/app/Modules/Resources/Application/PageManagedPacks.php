<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\ManagedPackFilter;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;

/**
 * Packs for management, in Category order then Pack order (Packs with no Category last), Drafts included. Needs
 * `resources.manage`. Filterable by Category, audience, state, Card Type and a case-insensitive match on the title.
 */
final readonly class PageManagedPacks
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private ResourceViews $views,
    ) {}

    /** @throws AccessDenied */
    public function __invoke(Actor $actor, ManagedPackFilter $filter, int $page, int $perPage): ManagedPackPage
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
        $filter = new ManagedPackFilter($filter->category, $filter->audience, $filter->state, $filter->cardType, $filter->titleContains === null ? null : trim($filter->titleContains));

        return new ManagedPackPage(
            $this->views->packs($this->packs->page($filter, $page, $perPage), false),
            $page,
            $perPage,
            $this->packs->count($filter),
        );
    }
}
