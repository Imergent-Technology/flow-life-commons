<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\SearchPeople;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * Pages one type's directory by name (ADR 0038, C3). The id set is this type's relationships,
 * optionally one status, and a set past {@see PeopleQuery::MAX_ID_SET} is refused rather than
 * truncated. Contact matches are not included: that is WP3.
 */
final readonly class ListRelationshipDirectory
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private SearchPeople $search,
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
        $people = ($this->search)(new PeopleQuery(
            $query,
            null,
            array_map(PersonId::fromString(...), $ids),
            $page,
            $perPage,
        ));
        $pageIds = array_map(fn ($person): string => $person->id->value, $people->people);
        $rows = $this->relationships->statusesFor($type->key, $pageIds);
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
            );
        }

        return new RelationshipDirectoryPage($entries, $people->page, $people->perPage, $people->total);
    }
}
