<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Shared\Domain\Actor;

/** Every Category in display order, with how many Packs hold each. Needs `resources.manage`. */
final readonly class ListCategories
{
    public function __construct(
        private AuthorizeAction $authorize,
        private CategoryRepository $categories,
        private ResourceViews $views,
    ) {}

    /**
     * @return list<CategoryView>
     *
     * @throws AccessDenied
     */
    public function __invoke(Actor $actor): array
    {
        ($this->authorize)($actor, Capability::ManageResources);

        return $this->views->categories($this->categories->all());
    }
}
