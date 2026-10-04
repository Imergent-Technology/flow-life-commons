<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\ResourceProjection;
use App\Shared\Domain\Actor;

/**
 * One Pack as the Guardian Console's viewer may have it: only the Cards they can see, numbered 1..n among themselves, with their
 * content (ADR 0037, decisions 45-47). Needs `resources.view`. A Pack that does not exist, is a Draft, is not Published to this
 * viewer's audience, or has no Card they may see is the same `ResourcePackNotFound`, with nothing to tell which: the answer must
 * not disclose what a viewer is not entitled to know exists. An invisible Card's content is never even read.
 */
final readonly class GetResourcePack
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private CategoryRepository $categories,
    ) {}

    /**
     * @throws AccessDenied
     * @throws ResourcePackNotFound
     */
    public function __invoke(Actor $actor, PackId $id): DeliveredPack
    {
        ($this->authorize)($actor, Capability::ViewResources);

        $pack = $this->packs->find($id) ?? throw new ResourcePackNotFound;
        $visible = ResourceProjection::visibleCards($pack, $this->cards->outlinesOf($id), DeliverySurface::guardianConsole());
        $category = $pack->categoryId === null ? null : $this->categories->find($pack->categoryId);
        if ($visible === [] || $category === null) {
            throw new ResourcePackNotFound;
        }

        return DeliveredPacks::of($pack, $category, $visible, $this->cards);
    }
}
