<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Crm\Application\Delegated\FindPersonCandidates;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\Actor;

/**
 * The limited lookup of intake (ADR 0038, P4), under the type's manage capability and its `people.lookup` feature. At most
 * ten candidates and a `truncated` flag. CRM does the matching: an address matches CRM contact emails exactly, a phone number
 * (7 or more digits) matches exactly on its digits, and anything else is a name "contains". Each candidate is an id, a
 * display name, what matched, and the Person's status in THIS type only (null when they have none).
 *
 * Never returned: a contact value, another type's relationship, a profile, a tag, a role or Account information. A partial
 * address or number matches nothing, so the lookup cannot be used to enumerate.
 */
final readonly class ListRelationshipCandidates
{
    public const int LIMIT = FindPersonCandidates::LIMIT;

    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private FindPersonCandidates $find,
    ) {}

    /**
     * @throws AccessDenied
     * @throws RelationshipNotFound the type does not offer the lookup
     * @throws InvalidRelationshipQuery
     */
    public function __invoke(Actor $actor, RelationshipType $type, string $query): RelationshipCandidateList
    {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);
        if (! $definition->hasFeature('people.lookup')) {
            throw new RelationshipNotFound;
        }

        $length = mb_strlen(trim($query));
        if ($length < 3) {
            throw new InvalidRelationshipQuery('q', 'The lookup needs at least 3 characters.');
        }
        if ($length > 255) {
            throw new InvalidRelationshipQuery('q', 'The lookup accepts at most 255 characters.');
        }

        $found = ($this->find)($query);
        $statuses = $this->relationships->statusesFor($type->key, array_map(fn ($candidate): string => $candidate->id->value, $found->candidates));
        $candidates = [];
        foreach ($found->candidates as $candidate) {
            $candidates[] = new RelationshipCandidate(
                new NamedPerson($candidate->id, $candidate->displayName),
                $candidate->matchedOn,
                $statuses[$candidate->id->value]['status'] ?? null,
            );
        }

        return new RelationshipCandidateList($candidates, $found->truncated);
    }
}
