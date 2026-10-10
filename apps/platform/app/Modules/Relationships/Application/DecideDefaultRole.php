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
 * Changes the default-role decision of an existing relationship (ADR 0038, K8).
 * Generated only for a type that has a default role. Recent verification is the route's.
 *
 * `grant` is accepted only while the locked row qualifies, and only with Access's
 * role-assignment authority. An identical decision does not move the revision.
 */
final readonly class DecideDefaultRole
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private RelationshipPresentation $presentation,
        private ApplyDefaultRoleDecision $decisions,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws RelationshipNotFound
     * @throws StaleRelationshipRevision
     * @throws RelationshipNotActive
     * @throws RoleProvisioningNotPermitted
     */
    public function __invoke(
        Actor $actor,
        RelationshipType $type,
        PersonId $person,
        RelationshipId $id,
        int $revision,
        DefaultRoleChoice $choice,
    ): RelationshipManagementView {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);
        $role = $definition->defaultRole ?? throw new RelationshipNotFound;

        return $this->database->transaction(function () use ($actor, $type, $person, $id, $revision, $choice, $definition, $role): RelationshipManagementView {
            $current = $this->relationships->lock($person, $type->key) ?? throw new RelationshipNotFound;
            $view = $this->presentation->management($definition, $current);
            if (! $current->id->equals($id) || $current->revision !== $revision) {
                throw new StaleRelationshipRevision($view);
            }
            $qualifies = $definition->qualifies($current->status);
            if ($choice === DefaultRoleChoice::Grant && ! $qualifies) {
                throw new RelationshipNotActive;
            }

            $outcome = ($this->decisions)($actor, $role, $current, $choice, $qualifies);
            $updated = $current->withProvisions($outcome->provisions);
            if ($outcome->decisionChanged) {
                $updated = $updated->withRevision($actor->personId, RelationshipTime::now());
                if (! $this->relationships->saveRevision($updated, $revision)) {
                    throw new StaleRelationshipRevision($view);
                }
            }

            return $this->presentation->management($definition, $updated);
        }, 3);
    }
}
