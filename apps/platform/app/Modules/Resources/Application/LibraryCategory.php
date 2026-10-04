<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Category;

/** A Category in the library, with the Packs a viewer may see in it. A Category with none is not listed at all. */
final readonly class LibraryCategory
{
    /** @param  list<LibraryPack>  $packs */
    public function __construct(public Category $category, public array $packs) {}
}
