<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;

/** One Card with its content for management, Drafts included. Needs `resources.manage`. A Card that is not in that Pack is not found. */
final readonly class GetManagedCard
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private ResourceViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws CardNotFound
     */
    public function __invoke(Actor $actor, PackId $pack, CardId $id): ManagedCardView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $this->packs->find($pack) ?? throw new PackNotFound;
        $card = $this->cards->find($id);
        if ($card === null || ! $card->packId->equals($pack)) {
            throw new CardNotFound;
        }

        return $this->views->card($card);
    }
}
