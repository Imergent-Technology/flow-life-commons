<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;

/**
 * Sets the order of ALL the Cards in a Pack, Drafts included, from the complete ordered list of their ids. Needs
 * `resources.manage`. The Pack's row is the lock, so a Card cannot be created or deleted while the set is being decided; the list
 * must be exactly the set that is there now (`order_mismatch` otherwise). Ordering is not editing: provenance is untouched, and
 * the stored order is never what a viewer sees (they number the Cards they can see among themselves).
 */
final readonly class ReorderCards
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  list<CardId>  $order
     *
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws OrderMismatch
     */
    public function __invoke(Actor $actor, PackId $pack, array $order): ManagedPackView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $locked = $this->database->transaction(function () use ($pack, $order) {
            $locked = $this->packs->lock($pack) ?? throw new PackNotFound;
            SiblingOrder::assertSameSet(
                array_map(static fn (CardOutline $o): string => $o->id->value, $this->cards->outlinesOf($pack)),
                array_map(static fn (CardId $id): string => $id->value, $order),
            );
            foreach ($order as $i => $id) {
                $this->cards->savePosition($id, $i + 1);
            }

            return $locked;
        }, 3);

        return $this->views->pack($locked);
    }
}
