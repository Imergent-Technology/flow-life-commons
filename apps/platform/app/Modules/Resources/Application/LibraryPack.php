<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Pack;

/** A Pack in the library listing: no content, and the count of the Cards THIS viewer can see in it. */
final readonly class LibraryPack
{
    public function __construct(public Pack $pack, public int $visibleCardCount) {}
}
