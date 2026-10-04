<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Card;

/**
 * A Card as a viewer receives it. `index` is its place among the Cards THIS viewer can see, counted from 1: the only position a
 * viewer ever learns (ADR 0037, decision 46). Content included; no state, revision, audience or provenance.
 */
final readonly class DeliveredCard
{
    public function __construct(public Card $card, public int $index) {}
}
