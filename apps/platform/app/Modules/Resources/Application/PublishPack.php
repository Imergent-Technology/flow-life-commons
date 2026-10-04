<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Publishes a Pack. Needs `resources.manage`. It must have a Category, at least one audience and at least one Published Card
 * (`pack_not_publishable`, naming what is missing), and keeps all three for as long as it is Published (ADR 0037, decisions 13-14).
 * Idempotent: publishing a Published Pack changes nothing. Under the Pack's lock, so a Card cannot be unpublished or deleted
 * between the check and the write.
 */
final readonly class PublishPack
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
     * @throws PackNotPublishable
     */
    public function __invoke(Actor $actor, PackId $id): ManagedPackView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $pack = $this->database->transaction(function () use ($actor, $id): Pack {
            $pack = $this->packs->lock($id) ?? throw new PackNotFound;
            if ($pack->isPublished()) {
                return $pack;
            }
            $published = count(array_filter($this->cards->outlinesOf($id), static fn (CardOutline $card): bool => $card->isPublished()));
            $unmet = $pack->unmetPublishRequirements($published);
            if ($unmet !== []) {
                throw new PackNotPublishable($unmet);
            }
            $changed = $pack->publishedBy($actor->personId, DateTimeImmutable::createFromInterface(now()));
            $this->packs->saveState($changed);

            return $changed;
        }, 3);

        return $this->views->pack($pack);
    }
}
