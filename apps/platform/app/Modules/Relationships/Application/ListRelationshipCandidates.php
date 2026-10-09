<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\SearchPeople;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\Actor;

/**
 * Name lookup for intake (ADR 0038, P4, names only in WP1). At most ten candidates. A name match
 * returns no contact value. Status is this type only. Email and phone matching is WP3.
 */
final readonly class ListRelationshipCandidates
{
    public const int LIMIT = 10;

    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private SearchPeople $search,
    ) {}

    /**
     * @throws AccessDenied
     */
    public function __invoke(Actor $actor, RelationshipType $type, string $query): RelationshipCandidateList
    {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);
        $length = mb_strlen(trim($query));
        if ($length < 3) {
            throw new InvalidRelationshipQuery('q', 'The lookup needs at least 3 characters.');
        }
        if ($length > 255) {
            throw new InvalidRelationshipQuery('q', 'The lookup accepts at most 255 characters.');
        }

        $page = ($this->search)(new PeopleQuery($query, null, null, 1, self::LIMIT + 1));
        $shown = array_slice($page->people, 0, self::LIMIT);
        $ids = array_map(fn ($person): string => $person->id->value, $shown);
        $statuses = $this->relationships->statusesFor($type->key, $ids);
        $candidates = [];
        foreach ($shown as $person) {
            $candidates[] = new RelationshipCandidate(
                new NamedPerson($person->id, $person->displayName),
                'display_name',
                $statuses[$person->id->value]['status'] ?? null,
            );
        }

        return new RelationshipCandidateList($candidates, $page->total > self::LIMIT);
    }
}
