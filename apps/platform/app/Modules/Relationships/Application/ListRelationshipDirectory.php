<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Crm\Application\Delegated\PeopleMatchingContact;
use App\Modules\Identity\Application\SearchPeople;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * Pages one type's directory (ADR 0038, C3). The id set is this type's relationships, optionally one status, and a set
 * past {@see PeopleQuery::MAX_ID_SET} is refused rather than truncated.
 *
 * Where the type reads basic details, the search also matches the PRIMARY email and PRIMARY phone of the Persons in that
 * set, through CRM's `PeopleMatchingContact`, and each entry carries the Person's primary email and phone (P2). A
 * non-primary method never matches and is never shown, so the directory cannot be used to probe it. Where the type does
 * not read basic details, neither the search nor the entries touch CRM.
 */
final readonly class ListRelationshipDirectory
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private SearchPeople $search,
        private PeopleMatchingContact $contacts,
        private RelationshipPresentation $presentation,
    ) {}

    /**
     * @throws AccessDenied
     * @throws UnknownRelationshipStatus
     * @throws RelationshipSearchTooBroad
     */
    public function __invoke(Actor $actor, RelationshipType $type, ?string $status, ?string $query, int $page, int $perPage): RelationshipDirectoryPage
    {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->viewCapability);
        if ($page < 1) {
            throw new InvalidRelationshipQuery('page', 'The page must be at least 1.');
        }
        if ($perPage < 1 || $perPage > PeopleQuery::MAX_PER_PAGE) {
            throw new InvalidRelationshipQuery('per_page', 'The page size must be between 1 and '.PeopleQuery::MAX_PER_PAGE.'.');
        }
        if ($status !== null && ! array_key_exists($status, $definition->states)) {
            throw new UnknownRelationshipStatus;
        }

        $ids = $this->relationships->personIds($type->key, $status);
        if (count($ids) > PeopleQuery::MAX_ID_SET) {
            throw new RelationshipSearchTooBroad;
        }
        $within = array_map(PersonId::fromString(...), $ids);
        $readsBasics = $definition->hasFeature('people.read_basics');
        $contactMatches = $readsBasics && $query !== null ? ($this->contacts)($query, $within) : [];
        $people = ($this->search)(new PeopleQuery(
            $query,
            $contactMatches === [] ? null : $contactMatches,
            $within,
            $page,
            $perPage,
        ));
        $pageIds = array_map(fn ($person): string => $person->id->value, $people->people);
        $rows = $this->relationships->statusesFor($type->key, $pageIds);
        $basics = $readsBasics ? $this->presentation->basicsOf(array_map(fn ($person): PersonId => $person->id, $people->people)) : [];
        $entries = [];
        foreach ($people->people as $person) {
            $row = $rows[$person->id->value] ?? null;
            if ($row === null) {
                continue;
            }
            $entries[] = new RelationshipDirectoryEntry(
                RelationshipId::fromString($row['id']),
                new NamedPerson($person->id, $person->displayName),
                $row['status'],
                new DateTimeImmutable($row['status_changed_at'], new \DateTimeZone('UTC')),
                $basics[$person->id->value] ?? null,
            );
        }

        return new RelationshipDirectoryPage($entries, $people->page, $people->perPage, $people->total);
    }
}
