<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\ResourceFileStore;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Publishes a Card, independently of its Pack. Needs `resources.manage`. It needs a title and, by Type, text content, an address,
 * or its file (`card_not_publishable`, naming what is missing). Idempotent. Takes the Pack's lock, then the Card's own row: an
 * authored edit arriving meanwhile waits, then finds the Card Published and is judged as a Published Card's edit, so a Card can
 * never be published on content an edit emptied in between (ADR 0037, decisions 22-23 and 56).
 *
 * A File Card's file must be on record AND in the store: one whose bytes are missing (after a partial restore, say) would publish a
 * Card whose download could only answer `asset_unavailable`, so it is refused as lacking its `file`.
 */
final readonly class PublishCard
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private ResourceViews $views,
        private ConnectionInterface $database,
        private ResourceFileStore $files,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws CardNotFound
     * @throws CardNotPublishable
     */
    public function __invoke(Actor $actor, PackId $pack, CardId $id): ManagedCardView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $card = $this->database->transaction(function () use ($actor, $pack, $id): Card {
            $this->packs->lock($pack) ?? throw new PackNotFound;
            $card = $this->cards->lock($id);
            if ($card === null || ! $card->packId->equals($pack)) {
                throw new CardNotFound;
            }
            if ($card->isPublished()) {
                return $card;
            }
            $unmet = $card->unmetPublishRequirements();
            if ($unmet === [] && $card->asset !== null && ! $this->files->exists($card->asset->storageKey)) {
                $unmet = [Card::NEEDS_FILE];
            }
            if ($unmet !== []) {
                throw new CardNotPublishable($unmet);
            }
            $changed = $card->publishedBy($actor->personId, DateTimeImmutable::createFromInterface(now()));
            $this->cards->saveState($changed);

            return $changed;
        }, 3);

        return $this->views->card($card);
    }
}
