<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Identity\Application\FindPeople;
use App\Shared\Domain\PersonId;

/**
 * Batched: for each Person, the id, the display name, and the value of the primary email and the primary phone, exactly as
 * stored (ADR 0038, P1, P2). Nothing else: no other contact method, no label, no profile field, no tag, no note.
 *
 * **It authorizes nothing and knows nothing of relationships.** Its callers are Relationships' use cases, each of which has
 * already decided that the Actor may read these three details of these Persons (P3). No HTTP class reaches it, and an
 * architecture test pins its callers. A Person who does not exist is absent from the answer.
 */
final readonly class ReadPersonBasics
{
    public function __construct(
        private FindPeople $people,
        private ContactMethodRepository $methods,
    ) {}

    /**
     * @param  list<PersonId>  $ids
     * @return array<string, PersonBasics> keyed by PersonId value
     */
    public function __invoke(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = ($this->people)($ids);
        $primaries = $this->methods->primaryValues(array_values(array_map(
            static fn ($summary): PersonId => $summary->id,
            $names,
        )));

        $basics = [];
        foreach ($names as $key => $summary) {
            $basics[$key] = new PersonBasics(
                $summary->id,
                $summary->displayName,
                $primaries[$key]['email'] ?? null,
                $primaries[$key]['phone'] ?? null,
            );
        }

        return $basics;
    }
}
