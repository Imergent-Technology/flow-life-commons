<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Pack;

/** A Pack in a reader's library listing (policy C): no content, and the count of the Cards THIS reader can see in it. */
final readonly class DeliveredListedPack
{
    public function __construct(public Pack $pack, public int $visibleCardCount) {}
}
