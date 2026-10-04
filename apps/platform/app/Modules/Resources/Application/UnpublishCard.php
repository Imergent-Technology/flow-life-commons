<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Returns a Card to Draft: absent from every viewer projection, kept, ordered and editable for managers. Needs
 * `resources.manage`. The reversible "archive for later" (ADR 0037, decision 22). Idempotent. Refused
 * `published_pack_requirement` when it is the LAST Published Card of a Published Pack: the Pack is never silently unpublished.
 * Under the Pack's lock, so it cannot race the Pack being published or another Card being unpublished.
 */
final readonly class UnpublishCard
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws CardNotFound
     * @throws PublishedPackRequirement
     */
    public function __invoke(Actor $actor, PackId $pack, CardId $id): ManagedCardView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $card = $this->database->transaction(function () use ($actor, $pack, $id): Card {
            $locked = $this->packs->lock($pack) ?? throw new PackNotFound;
            $card = $this->cards->find($id);
            if ($card === null || ! $card->packId->equals($pack)) {
                throw new CardNotFound;
            }
            if (! $card->isPublished()) {
                return $card;
            }
            if ($locked->isPublished() && self::publishedCount($this->cards->outlinesOf($pack)) === 1) {
                throw new PublishedPackRequirement(Pack::NEEDS_PUBLISHED_CARD);
            }
            $changed = $card->unpublishedBy($actor->personId, DateTimeImmutable::createFromInterface(now()));
            $this->cards->saveState($changed);

            return $changed;
        }, 3);

        return $this->views->card($card);
    }

    /** @param  list<CardOutline>  $outlines */
    private static function publishedCount(array $outlines): int
    {
        return count(array_filter($outlines, static fn (CardOutline $o): bool => $o->isPublished()));
    }
}
