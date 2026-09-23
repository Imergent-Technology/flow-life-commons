<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\PersonId;

/**
 * The batched form of "what is this Person called": other modules composing a list or a detail
 * view ask this instead of reading Identity's tables, so an admin surface for another module
 * (Membership's, first) can show a display name without an N+1 lookup per row.
 */
final readonly class FindPeople
{
    public function __construct(private PersonRepository $people) {}

    /**
     * @param  list<PersonId>  $ids
     * @return array<string, PersonSummary> keyed by PersonId value; an id with no matching Person is omitted
     */
    public function __invoke(array $ids): array
    {
        $summaries = [];
        foreach ($this->people->findMany($ids) as $person) {
            $summaries[$person->id->value] = new PersonSummary($person->id, $person->displayName);
        }

        return $summaries;
    }
}
