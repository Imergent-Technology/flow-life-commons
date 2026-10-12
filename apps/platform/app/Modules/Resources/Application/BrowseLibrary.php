<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Shared\Domain\Actor;

/**
 * Policy A (ADR 0038, R5): the privileged library, every Category and Pack in any state (R6), narrowed by the filters (R8). Needs
 * `resources.view` and nothing else: not `resources.manage`, not an audience, a relationship or Membership. See `ViewerLibrary`.
 */
final readonly class BrowseLibrary
{
    public function __construct(private AuthorizeAction $authorize, private ViewerLibrary $library) {}

    /**
     * @return list<ViewerCategory>
     *
     * @throws AccessDenied
     */
    public function __invoke(Actor $actor, LibraryFilter $filter): array
    {
        ($this->authorize)($actor, Capability::ViewResources);

        return $this->library->browse($filter);
    }
}
