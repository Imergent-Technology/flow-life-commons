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

/**
 * The file of any File Card, Drafts included, for management (ADR 0037, decision 66). Needs `resources.manage`. A Card that is not in
 * that Pack is not found; a Card with no file (not a File Card) or whose bytes are missing from the store is `asset_unavailable`,
 * which management may know.
 */
final readonly class DownloadManagedFile
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private OpenAsset $open,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws CardNotFound
     * @throws AssetUnavailable
     */
    public function __invoke(Actor $actor, PackId $pack, CardId $id): FileDownload
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $this->packs->find($pack) ?? throw new PackNotFound;
        $card = $this->cards->find($id);
        if ($card === null || ! $card->packId->equals($pack)) {
            throw new CardNotFound;
        }

        return ($this->open)($card->asset ?? throw new AssetUnavailable);
    }
}
