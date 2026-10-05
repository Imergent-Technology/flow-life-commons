<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\ResourceProjection;

/**
 * What the Guardian Console's viewer may have of one Pack: the ONE lookup behind a delivered Pack and a delivered file, so the two
 * can never disagree about whether a Card is visible (ADR 0037, decisions 45-46 and 66). Internal to Resources; the caller has
 * already asked for `resources.view`.
 *
 * A Pack that does not exist, is a Draft, is not Published to the viewer's audience, or has no Card they may see is the same
 * `ResourcePackNotFound`, with nothing to tell which.
 */
final readonly class GuardianDelivery
{
    public function __construct(
        private PackRepository $packs,
        private CardRepository $cards,
        private CategoryRepository $categories,
    ) {}

    /**
     * @return array{Pack, Category, list<CardOutline>} the Pack, its Category and its visible Cards, in order
     *
     * @throws ResourcePackNotFound
     */
    public function visible(PackId $id): array
    {
        $pack = $this->packs->find($id) ?? throw new ResourcePackNotFound;
        $visible = ResourceProjection::visibleCards($pack, $this->cards->outlinesOf($id), DeliverySurface::guardianConsole());
        $category = $pack->categoryId === null ? null : $this->categories->find($pack->categoryId);
        if ($visible === [] || $category === null) {
            throw new ResourcePackNotFound;
        }

        return [$pack, $category, $visible];
    }
}
