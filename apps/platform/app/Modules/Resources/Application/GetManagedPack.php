<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;

/** One Pack for management, with every Card in it (Drafts included, no content). Needs `resources.manage`. */
final readonly class GetManagedPack
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private ResourceViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PackNotFound
     */
    public function __invoke(Actor $actor, PackId $id): ManagedPackView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        return $this->views->pack($this->packs->find($id) ?? throw new PackNotFound);
    }
}
