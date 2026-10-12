<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Identity\Application\PeopleQuery;
use App\Shared\Domain\PersonId;
use InvalidArgumentException;

/**
 * Among the Persons it is given, those whose PRIMARY email contains the text, or whose PRIMARY phone contains its digits
 * (3 or more) (ADR 0038, P1). It never matches a non-primary method, so a directory search cannot probe the contact
 * methods that a relationship's reader is not shown. A caller that can read a Person's primary values (P2) learns nothing
 * from a match that it could not read.
 *
 * It is the directory's search only. The exact-match candidate lookup is a separate contract, {@see FindPersonCandidates}.
 * The set it searches within is the caller's own scope and is bounded like Identity's id sets. It authorizes nothing.
 */
final readonly class PeopleMatchingContact
{
    public function __construct(private ContactMethodRepository $methods) {}

    /**
     * @param  list<PersonId>  $within
     * @return list<PersonId>
     */
    public function __invoke(string $text, array $within): array
    {
        if (count($within) > PeopleQuery::MAX_ID_SET) {
            throw new InvalidArgumentException('A contact search covers at most '.PeopleQuery::MAX_ID_SET.' Person ids.');
        }
        $text = trim($text);
        if ($text === '' || $within === []) {
            return [];
        }

        return $this->methods->personIdsMatchingPrimary($text, $within, PeopleQuery::MAX_ID_SET);
    }
}
