<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\PackId;
use App\Shared\Domain\Actor;

/**
 * One Pack as the Guardian Console's viewer may have it: only the Cards they can see, numbered 1..n among themselves, with their
 * content (ADR 0037, decisions 45-47). Needs `resources.view`. A Pack that does not exist, is a Draft, is not Published to this
 * viewer's audience, or has no Card they may see is the same `ResourcePackNotFound`, with nothing to tell which: the answer must
 * not disclose what a viewer is not entitled to know exists. An invisible Card's content is never even read. The visibility lookup
 * is `GuardianDelivery`, shared with the file download, so the two cannot disagree.
 */
final readonly class GetResourcePack
{
    public function __construct(
        private AuthorizeAction $authorize,
        private GuardianDelivery $delivery,
        private CardRepository $cards,
    ) {}

    /**
     * @throws AccessDenied
     * @throws ResourcePackNotFound
     */
    public function __invoke(Actor $actor, PackId $id): DeliveredPack
    {
        ($this->authorize)($actor, Capability::ViewResources);

        [$pack, $category, $visible] = $this->delivery->visible($id);

        return DeliveredPacks::of($pack, $category, $visible, $this->cards);
    }
}
