<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Identity\Application\FindPeopleNamed;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\PersonSummary;
use App\Modules\Identity\Application\RegisterPerson;
use App\Modules\Identity\Application\SearchPeople;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Registers a Person with their first CRM information, in ONE transaction, and gives the advisory duplicate check
 * (ADR 0034, ADR 0038 P1). It is CRM's one implementation of "add a Person to the directory": `RegisterContact` (the CRM
 * route) and the delegated `RegisterMinimalPerson` (a relationship's intake) both call it, so the rules cannot drift.
 *
 * **It authorizes nothing.** Its callers do, each by their own capability. It opens its own transaction, which JOINS an
 * enclosing one as a savepoint, so a relationship's intake commits or rolls back with the Person it created.
 *
 * Possible duplicates are advice, never a merge: see {@see RegisterContact}. The check is made against the data as it is
 * at the moment of the call, before anything is written, and it is not race-proof by design.
 */
final readonly class PersonRegistration
{
    public function __construct(
        private RegisterPerson $registerPerson,
        private SearchPeople $search,
        private FindPeopleNamed $named,
        private ContactProfileRepository $profiles,
        private ContactMethodRepository $methods,
        private ContactMethodWriter $writer,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  list<NewContactMethod>  $contactMethods
     *
     * @throws InvalidContactInput
     * @throws DuplicateContactMethod the same kind and value twice in one request
     * @throws PossibleDuplicate
     */
    public function __invoke(
        Actor $actor,
        string $displayName,
        ?string $howWeKnow,
        ?string $affiliation,
        array $contactMethods,
        bool $confirmDistinct,
    ): PersonId {
        $this->assertValid($contactMethods);
        if (! $confirmDistinct) {
            $candidates = $this->candidates($displayName, $contactMethods);
            if ($candidates !== []) {
                throw new PossibleDuplicate($candidates);
            }
        }

        $now = DateTimeImmutable::createFromInterface(now());

        return $this->database->transaction(function () use ($actor, $displayName, $howWeKnow, $affiliation, $contactMethods, $now): PersonId {
            try {
                $person = ($this->registerPerson)($displayName);
            } catch (InvalidArgumentException) {
                throw new InvalidContactInput('display_name', 'A name is 1 to 255 characters.');
            }
            if ($howWeKnow !== null || $affiliation !== null || $contactMethods !== []) {
                $profile = $this->profiles->lock($person->id, $now);
                $this->profiles->save($profile->with(['how_we_know' => $howWeKnow, 'affiliation' => $affiliation], $actor->accountId, $now));
                foreach ($contactMethods as $new) {
                    $this->writer->add($person->id, $new, $now, $actor->accountId);
                }
            }

            return $person->id;
        }, 3);
    }

    /**
     * Rejects what would fail inside the transaction before it starts, and a repeated method, with CRM's own messages.
     *
     * @param  list<NewContactMethod>  $contactMethods
     */
    private function assertValid(array $contactMethods): void
    {
        $seen = [];
        foreach ($contactMethods as $new) {
            $display = $new->kind->display($new->value);
            $key = $new->kind->value.'|'.$new->kind->searchValue($display);
            if (isset($seen[$key])) {
                throw new DuplicateContactMethod;
            }
            $seen[$key] = true;
        }
    }

    /**
     * @param  list<NewContactMethod>  $contactMethods
     * @return list<DuplicateCandidate>
     */
    private function candidates(string $displayName, array $contactMethods): array
    {
        /** @var array<string, array{person: PersonSummary, on: array<string, true>}> $found */
        $found = [];

        $emails = [];
        foreach ($contactMethods as $new) {
            if ($new->kind === ContactMethodKind::Email) {
                $emails[] = $new->kind->searchValue($new->kind->display($new->value));
            }
        }
        $emailPeople = [];
        foreach (array_unique($emails) as $searchValue) {
            foreach ($this->methods->personIdsWithSearchValue(ContactMethodKind::Email, $searchValue) as $id) {
                $emailPeople[$id->value] = $id;
            }
        }
        if ($emailPeople !== []) {
            $page = ($this->search)(new PeopleQuery(null, null, array_values($emailPeople), 1, PeopleQuery::MAX_PER_PAGE));
            foreach ($page->people as $person) {
                $found[$person->id->value] = ['person' => $person, 'on' => ['email' => true]];
            }
        }

        // The same name, ignoring case: an exact lookup in Identity, so no result can be missed by where it sorts.
        foreach (($this->named)($displayName) as $person) {
            $found[$person->id->value] = ['person' => $person, 'on' => ($found[$person->id->value]['on'] ?? []) + ['display_name' => true]];
        }

        $candidates = [];
        foreach ($found as $entry) {
            $candidates[] = new DuplicateCandidate($entry['person'], array_keys($entry['on']));
        }

        return $candidates;
    }
}
