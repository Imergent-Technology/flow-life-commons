<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\PackId;
use App\Shared\Domain\Actor;

/**
 * Policy C (ADR 0038, R11): one Pack as this Person may have it, only the Cards they can see, numbered 1..n among themselves, with
 * their content. Asks for no capability. A Pack that does not exist, is a Draft, is not Published to this Person's audiences, or has no
 * Card they may see is the same `ResourcePackNotFound`, with nothing to tell which. An invisible Card's content is never even read.
 * The visibility lookup is `AudienceDelivery`, shared with the file download, so the two cannot disagree.
 */
final readonly class GetDeliveredPack
{
    public function __construct(private AudienceDelivery $delivery, private CardRepository $cards) {}

    /** @throws ResourcePackNotFound */
    public function __invoke(Actor $actor, PackId $id): DeliveredPack
    {
        [$pack, $category, $visible] = $this->delivery->visible($actor, $id);

        return DeliveredPacks::of($pack, $category, $visible, $this->cards);
    }
}
