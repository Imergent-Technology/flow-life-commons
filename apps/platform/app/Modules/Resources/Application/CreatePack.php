<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Creates an empty Draft Pack, with no audience and no Cards, optionally already in a Category (appended to its Packs). Needs
 * `resources.manage`. The Category's row lock is held while the Pack is added to it, which serialises this against a reorder of
 * the Category's Packs and against deleting the Category (ADR 0037, decision 57).
 */
final readonly class CreatePack
{
    public function __construct(
        private AuthorizeAction $authorize,
        private CategoryRepository $categories,
        private PackRepository $packs,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws InvalidResourceInput
     * @throws UnknownCategory
     */
    public function __invoke(Actor $actor, string $title, ?string $summary, bool $isSeries, ?CategoryId $category): ManagedPackView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $pack = $this->database->transaction(function () use ($actor, $title, $summary, $isSeries, $category): Pack {
            if ($category !== null) {
                $this->categories->lock($category) ?? throw new UnknownCategory;
            }
            $pack = Pack::draft(PackId::generate(), $category, $this->packs->nextPositionIn($category), $title, $summary, $isSeries, $actor->personId, DateTimeImmutable::createFromInterface(now()));
            $this->packs->add($pack);

            return $pack;
        }, 3);

        return $this->views->pack($pack);
    }
}
