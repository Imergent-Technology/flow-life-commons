<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;

/**
 * Permanently deletes one relationship (ADR 0038, F12). The type's manage capability, then recent
 * verification on the route. One security event, in the same transaction, holding ids and counts
 * and none of the field values. The Person and their other relationships are not touched.
 * G10 registers no dependents. Grant withdrawal is the WP2B seam and removes nothing today.
 */
final readonly class DeleteRelationship
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private RelationshipPresentation $presentation,
        private RelationshipDependents $dependents,
        private RelationshipGrantWithdrawal $grants,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws RelationshipNotFound
     * @throws StaleRelationshipRevision
     * @throws RelationshipInUse
     */
    public function __invoke(Actor $actor, RelationshipType $type, PersonId $person, RelationshipId $id, int $revision): void
    {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);
        if (! $definition->deletion) {
            throw new RelationshipNotFound;
        }

        $this->database->transaction(function () use ($actor, $type, $person, $id, $revision, $definition): void {
            $current = $this->relationships->lock($person, $type->key) ?? throw new RelationshipNotFound;
            if (! $current->id->equals($id) || $current->revision !== $revision) {
                throw new StaleRelationshipRevision($this->presentation->management($definition, $current));
            }
            if ($this->dependents->blocks($current->id)) {
                throw new RelationshipInUse;
            }

            $withdrawn = $this->grants->withdraw($actor, $current->id);
            $counts = $this->relationships->delete($current->id);

            ($this->record)(
                'relationship.deleted', SecurityEventOutcome::Success, $actor, $person, null, null, null,
                [
                    'relationship_id' => $current->id->value,
                    'relationship_type' => $type->key,
                    'status' => $current->status,
                    'history_rows' => $counts->history,
                    'field_values' => $counts->fields,
                    'withdrawn_grants' => $withdrawn,
                ],
            );
        }, 3);
    }
}
