<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;

/**
 * Moves a relationship along its type's transitions (ADR 0038, F9, F10). Asking for the current
 * status, at the current revision, changes nothing and writes no history. A stale instance or
 * revision writes nothing. Deactivation keeps the id, the fields and the history.
 */
final readonly class ChangeRelationshipStatus
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private RelationshipPresentation $presentation,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws RelationshipNotFound
     * @throws StaleRelationshipRevision
     * @throws DefaultRoleNotApplicable
     * @throws TransitionNotAllowed
     */
    public function __invoke(
        Actor $actor,
        RelationshipType $type,
        PersonId $person,
        RelationshipId $id,
        int $revision,
        string $status,
        bool $defaultRoleSent,
    ): RelationshipManagementView {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);

        return $this->database->transaction(function () use ($actor, $type, $person, $id, $revision, $status, $defaultRoleSent, $definition): RelationshipManagementView {
            $current = $this->relationships->lock($person, $type->key) ?? throw new RelationshipNotFound;
            $view = $this->presentation->management($definition, $current);
            if (! $current->id->equals($id) || $current->revision !== $revision) {
                throw new StaleRelationshipRevision($view);
            }
            if ($defaultRoleSent) {
                throw new DefaultRoleNotApplicable;
            }
            if ($current->status === $status) {
                return $view;
            }
            if (! $definition->allowsTransition($current->status, $status)) {
                throw new TransitionNotAllowed;
            }

            $updated = $current->withStatus($status, $actor->personId, RelationshipTime::now());
            if (! $this->relationships->saveStatus($updated, $revision)) {
                throw new StaleRelationshipRevision($view);
            }

            return $this->presentation->management($definition, $updated);
        }, 3);
    }
}
