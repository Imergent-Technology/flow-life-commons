<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\Pack;

/**
 * Builds the delivered shape of a Pack from the Cards the projection let through, for delivery and preview alike: loads their
 * content (only theirs: an invisible Card's content is never read), and numbers them 1..n among themselves. Internal to Resources.
 */
final class DeliveredPacks
{
    /** @param  list<CardOutline>  $visible  already filtered and ordered by ResourceProjection */
    public static function of(Pack $pack, Category $category, array $visible, CardRepository $cards): DeliveredPack
    {
        $delivered = [];
        foreach ($cards->findMany(array_map(static fn (CardOutline $o) => $o->id, $visible)) as $i => $card) {
            $delivered[] = new DeliveredCard($card, $i + 1);
        }

        return new DeliveredPack($pack, $category, $delivered);
    }
}
