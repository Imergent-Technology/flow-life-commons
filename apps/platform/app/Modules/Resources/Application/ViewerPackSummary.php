<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Pack;

/**
 * A Pack in the privileged library's listing (ADR 0038, R6-R7): no content, the count of ALL its Cards and of its Draft ones, its
 * state and audiences, when it was last edited and the last editor's display name (null if that Person is no longer known). No
 * revision, stored position or creator is carried.
 */
final readonly class ViewerPackSummary
{
    public function __construct(public Pack $pack, public int $cardCount, public int $draftCardCount, public ?string $editor) {}
}
