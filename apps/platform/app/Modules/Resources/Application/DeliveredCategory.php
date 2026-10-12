<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Category;

/** A Category in a reader's library (policy C), with the Packs THIS reader may see in it. A Category with none is not listed at all. */
final readonly class DeliveredCategory
{
    /** @param  list<DeliveredListedPack>  $packs */
    public function __construct(public Category $category, public array $packs) {}
}
