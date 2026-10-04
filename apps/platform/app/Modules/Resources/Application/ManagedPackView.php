<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\Pack;

/**
 * A Pack for management: its fields, Category, audiences, state and revision, how many Cards it holds in total and how many are
 * Published, and who created and last edited it. `cards` is its contents list (Drafts included, no content) when the view is of
 * one Pack, and null in a list of Packs.
 */
final readonly class ManagedPackView
{
    /** @param  list<ManagedCardOutlineView>|null  $cards */
    public function __construct(
        public Pack $pack,
        public ?Category $category,
        public int $cardCount,
        public int $publishedCardCount,
        public ?array $cards,
        public ResourcePerson $createdBy,
        public ResourcePerson $updatedBy,
    ) {}
}
