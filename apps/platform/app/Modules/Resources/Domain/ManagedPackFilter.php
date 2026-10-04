<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * What the management list may be narrowed by (ADR 0037, decision 48): Category, audience, publication state, Card Type, and a
 * case-insensitive match on the Pack's title. Management discloses nothing a manager may not see, so these filter in the
 * database; the consumer library never does (it searches the projection).
 */
final readonly class ManagedPackFilter
{
    public function __construct(
        public ?CategoryId $category = null,
        public ?Audience $audience = null,
        public ?PublicationState $state = null,
        public ?CardType $cardType = null,
        public ?string $titleContains = null,
    ) {}
}
