<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Interaction;
use App\Modules\Identity\Application\FindPeople;

/**
 * Names the authors of interactions, for every use case that returns them. Internal to Crm. Identity owns Person names, so
 * this asks Identity's batched `FindPeople` (one query for a whole page) and never reads `people`.
 */
final readonly class InteractionViews
{
    public function __construct(private FindPeople $findPeople) {}

    /**
     * @param  list<Interaction>  $interactions
     * @return list<InteractionView>
     */
    public function of(array $interactions): array
    {
        $ids = [];
        foreach ($interactions as $interaction) {
            $ids[$interaction->authorPersonId->value] = $interaction->authorPersonId;
            if ($interaction->updatedByPersonId !== null) {
                $ids[$interaction->updatedByPersonId->value] = $interaction->updatedByPersonId;
            }
        }
        $names = $ids === [] ? [] : ($this->findPeople)(array_values($ids));

        return array_map(static fn (Interaction $interaction): InteractionView => new InteractionView(
            $interaction,
            $names[$interaction->authorPersonId->value] ?? null,
            $interaction->updatedByPersonId === null ? null : ($names[$interaction->updatedByPersonId->value] ?? null),
        ), $interactions);
    }
}
