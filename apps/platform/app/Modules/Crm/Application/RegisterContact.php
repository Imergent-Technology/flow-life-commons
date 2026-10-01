<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\PersonSummary;
use App\Modules\Identity\Application\RegisterPerson;
use App\Modules\Identity\Application\SearchPeople;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Adds a Person to the directory with their first CRM information: Identity's `RegisterPerson` creates the Person (no
 * Account, no Membership, no access) and CRM records the profile fields and contact methods, in ONE transaction, so a
 * CRM failure leaves no Person behind.
 *
 * **Possible duplicates are advice, never a merge.** Before anything is created the server looks for Persons who share
 * an EMAIL recorded in CRM with one submitted, or whose display name is the same ignoring case, and refuses with
 * `PossibleDuplicate` and the candidates (id and display name, directory information). The caller may then register
 * again with `$confirmDistinct`, which skips only this advisory check, never validation. The check is made on the
 * server at the moment of the request, so a screen that looked a minute ago cannot make a stale all-clear.
 *
 * **What it deliberately does not check:** an Account's login email. Telling a CRM user "this address belongs to
 * Ada's Account" would let `crm.people.manage` probe data guarded by `identity.accounts.view` (ADR 0034), and CRM may
 * not read Account storage. Two Persons may also share a phone number, so a phone match is not advice either.
 */
final readonly class RegisterContact
{
    /** How far down the contains-matches for the display name the exact-name check looks (pages of 100). */
    private const int NAME_PAGES = 10;

    public function __construct(
        private AuthorizeAction $authorize,
        private RegisterPerson $registerPerson,
        private SearchPeople $search,
        private ContactProfileRepository $profiles,
        private ContactMethodRepository $methods,
        private ContactMethodWriter $writer,
        private ReadPersonRecord $read,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  list<NewContactMethod>  $contactMethods
     *
     * @throws AccessDenied
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
    ): PersonRecord {
        ($this->authorize)($actor, Capability::ManagePeople);

        $this->assertValid($contactMethods);

        if (! $confirmDistinct) {
            $candidates = $this->candidates($displayName, $contactMethods);
            if ($candidates !== []) {
                throw new PossibleDuplicate($candidates);
            }
        }

        $now = DateTimeImmutable::createFromInterface(now());

        $personId = $this->database->transaction(function () use ($actor, $displayName, $howWeKnow, $affiliation, $contactMethods, $now) {
            try {
                $person = ($this->registerPerson)($displayName);
            } catch (InvalidArgumentException) {
                throw new InvalidContactInput('display_name', 'A name is 1 to 255 characters.');
            }

            if ($howWeKnow !== null || $affiliation !== null || $contactMethods !== []) {
                $profile = $this->profiles->lock($person->id, $now);
                $this->profiles->save($profile->with(['how_we_know' => $howWeKnow, 'affiliation' => $affiliation], $actor->accountId, $now));
                foreach ($contactMethods as $new) {
                    $this->writer->add($person->id, $new, $now);
                }
            }

            return $person->id;
        }, 3);

        return ($this->read)($personId);
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

        $name = mb_strtolower(trim($displayName));
        if ($name !== '') {
            for ($pageNumber = 1; $pageNumber <= self::NAME_PAGES; $pageNumber++) {
                $page = ($this->search)(new PeopleQuery($name, null, null, $pageNumber, PeopleQuery::MAX_PER_PAGE));
                foreach ($page->people as $person) {
                    if (mb_strtolower(trim($person->displayName)) === $name) {
                        $found[$person->id->value] = ['person' => $person, 'on' => ($found[$person->id->value]['on'] ?? []) + ['display_name' => true]];
                    }
                }
                if ($pageNumber >= $page->lastPage()) {
                    break;
                }
            }
        }

        $candidates = [];
        foreach ($found as $entry) {
            $candidates[] = new DuplicateCandidate($entry['person'], array_keys($entry['on']));
        }

        return $candidates;
    }
}
