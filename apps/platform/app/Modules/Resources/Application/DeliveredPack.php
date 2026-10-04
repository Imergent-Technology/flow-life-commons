<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\Pack;

/**
 * A Pack as a viewer receives it: its title, summary, Series flag and Category, and only the Cards that viewer may see, numbered
 * 1..n among themselves. One visible Card or several is the viewer's, not stored.
 */
final readonly class DeliveredPack
{
    /** @param  list<DeliveredCard>  $cards */
    public function __construct(public Pack $pack, public Category $category, public array $cards) {}
}
