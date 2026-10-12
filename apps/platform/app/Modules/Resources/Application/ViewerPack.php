<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Category;
use App\Modules\Resources\Domain\Pack;

/** One Pack as the privileged viewer reads it: every Card, Drafts included, whatever the Pack's state. `category` is null for an uncategorized Pack. */
final readonly class ViewerPack
{
    /** @param  list<ViewerCard>  $cards */
    public function __construct(public Pack $pack, public ?Category $category, public int $draftCardCount, public ?string $editor, public array $cards) {}
}
