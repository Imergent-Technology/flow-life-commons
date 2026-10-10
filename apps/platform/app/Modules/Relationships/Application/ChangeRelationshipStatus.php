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
 * Moves a relationship along its type's transitions (ADR 0038, F9, F10, K7). Asking for the
 * current status, at the current revision, changes nothing: `default_role` is ignored, and
 * this is not a provisioning operation. A stale instance or revision writes nothing.
 *
 * Leaving a qualifying state withdraws sourced grants in this transaction. Entering one
 * requires an explicit default-role decision when the type has a default role. The recorded
 * decision is kept across inactivation.
 */
final readonly class ChangeRelationshipStatus
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private RelationshipPresentation $presentation,
        private ApplyDefaultRoleDecision $decisions,
        private RelationshipGrantWithdrawal $grants,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws RelationshipNotFound
     * @throws StaleRelationshipRevision
     * @throws DefaultRoleNotApplicable
     * @throws DefaultRoleDecisionRequired
     * @throws InvalidDefaultRoleChoice
     * @throws RoleProvisioningNotPermitted
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
        mixed $defaultRole = null,
    ): RelationshipManagementView {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);

        return $this->database->transaction(function () use ($actor, $type, $person, $id, $revision, $status, $defaultRoleSent, $defaultRole, $definition): RelationshipManagementView {
            $current = $this->relationships->lock($person, $type->key) ?? throw new RelationshipNotFound;
            $view = $this->presentation->management($definition, $current);
            if (! $current->id->equals($id) || $current->revision !== $revision) {
                throw new StaleRelationshipRevision($view);
            }
            if ($current->status === $status) {
                return $view;
            }
            if (! $definition->allowsTransition($current->status, $status)) {
                throw new TransitionNotAllowed;
            }
            $choice = DefaultRoleRequest::choice($defaultRoleSent, $defaultRole);
            DefaultRoleRequest::guard($definition, $status, $choice);

            $updated = $current->withStatus($status, $actor->personId, RelationshipTime::now());
            if (! $this->relationships->saveStatus($updated, $revision)) {
                throw new StaleRelationshipRevision($view);
            }
            if ($definition->defaultRole !== null && $definition->qualifies($status) && $choice !== null) {
                $outcome = ($this->decisions)($actor, $definition->defaultRole, $updated, $choice, true, true);
                $updated = $updated->withProvisions($outcome->provisions);
            }
            if ($definition->defaultRole !== null && ! $definition->qualifies($status)) {
                // Inactivation withdraws whether or not the request mentioned a role. The decision row stays.
                $this->grants->withdraw($actor, $current->id);
            }

            return $this->presentation->management($definition, $updated);
        }, 3);
    }
}
