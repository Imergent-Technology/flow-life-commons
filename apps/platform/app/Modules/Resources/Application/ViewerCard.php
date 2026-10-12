<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\AudienceSet;
use App\Modules\Resources\Domain\Card;

/**
 * A Card as the privileged viewer reads it (ADR 0038, R6-R7): its latest saved content, its place among ALL the Pack's Cards
 * counted from 1, its state and audience mode, who it effectively targets, and the last editor's display name.
 */
final readonly class ViewerCard
{
    public function __construct(public Card $card, public int $index, public AudienceSet $audiences, public ?string $editor) {}
}
