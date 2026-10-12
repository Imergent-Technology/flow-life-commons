<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Category;

/** A group of the privileged library: a Category with the Packs the filters kept, or, with a null Category, the Packs that have none. */
final readonly class ViewerCategory
{
    /** @param  list<ViewerPackSummary>  $packs */
    public function __construct(public ?Category $category, public array $packs) {}
}
