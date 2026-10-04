<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Category;

/** A Category for management: its fields, how many Packs hold it (in any state) and who created and last edited it. */
final readonly class CategoryView
{
    public function __construct(
        public Category $category,
        public int $packCount,
        public ResourcePerson $createdBy,
        public ResourcePerson $updatedBy,
    ) {}
}
