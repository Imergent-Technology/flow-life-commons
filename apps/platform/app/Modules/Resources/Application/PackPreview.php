<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\PublicationState;

/**
 * What an editor sees when previewing a Pack as one audience (ADR 0037, decision 49): the delivery shape that audience would
 * receive if the Pack were Published as it stands, plus the facts an editor needs to read it. `pack` is null when that audience
 * would see nothing, which is reported rather than hidden because the caller may see everything.
 */
final readonly class PackPreview
{
    public function __construct(
        public Audience $audience,
        public PublicationState $packState,
        public bool $audienceTargeted,
        public ?DeliveredPack $pack,
    ) {}

    public function visible(): bool
    {
        return $this->pack !== null;
    }
}
