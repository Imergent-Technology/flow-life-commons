<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Returns a Pack to Draft. Needs `resources.manage`. Nothing is deleted or changed but the state, and none of its Cards are
 * delivered while it is a Draft, whatever their own state (ADR 0037, decision 12). Reversible and idempotent.
 */
final readonly class UnpublishPack
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PackNotFound
     */
    public function __invoke(Actor $actor, PackId $id): ManagedPackView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $pack = $this->database->transaction(function () use ($actor, $id): Pack {
            $pack = $this->packs->lock($id) ?? throw new PackNotFound;
            $changed = $pack->unpublishedBy($actor->personId, DateTimeImmutable::createFromInterface(now()));
            if ($changed !== $pack) {
                $this->packs->saveState($changed);
            }

            return $changed;
        }, 3);

        return $this->views->pack($pack);
    }
}
