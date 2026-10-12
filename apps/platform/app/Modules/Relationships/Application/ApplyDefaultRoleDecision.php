<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\ProvisionableRole;
use App\Modules\Relationships\Domain\RelationshipRecord;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\StoredRoleProvision;
use App\Shared\Domain\Actor;

/**
 * Records one explicit default-role decision and the Access grant or withdrawal that
 * belongs with it (ADR 0038, K5, K7, K11). The caller holds the transaction and the
 * relationship row lock. A grant refusal rolls that transaction back.
 *
 * An identical decision does not rewrite who decided it or when. `defer` never erases
 * a declined decision. A grant is refused, before Access is called, when the relationship
 * will not be in a qualifying state.
 */
final readonly class ApplyDefaultRoleDecision
{
    public function __construct(
        private RelationshipRepository $relationships,
        private GrantRelationshipRole $grantRole,
        private RelationshipGrantWithdrawal $withdrawal,
    ) {}

    /**
     * @throws RelationshipNotActive
     * @throws RoleProvisioningNotPermitted
     */
    public function __invoke(
        Actor $actor,
        ProvisionableRole $role,
        RelationshipRecord $relationship,
        DefaultRoleChoice $choice,
        bool $willQualify,
        bool $recordGrantAnew = false,
    ): DefaultRoleOutcome {
        if ($choice === DefaultRoleChoice::Grant && ! $willQualify) {
            throw new RelationshipNotActive;
        }

        $current = $this->current($relationship, $role);

        if ($choice === DefaultRoleChoice::Defer) {
            if ($current === null || $current->decision === StoredRoleProvision::Declined) {
                return new DefaultRoleOutcome(false, $relationship->provisions);
            }
            $this->relationships->deleteProvision($relationship->id, $role->value);
            $this->withdrawal->withdraw($actor, $relationship->id);

            return new DefaultRoleOutcome(true, $this->without($relationship, $role));
        }

        if ($choice === DefaultRoleChoice::Decline) {
            $already = $current !== null && $current->decision === StoredRoleProvision::Declined;
            $stored = $current;
            if (! $already) {
                $stored = new StoredRoleProvision(
                    $role->value,
                    StoredRoleProvision::Declined,
                    RelationshipTime::now(),
                    $actor->personId,
                );
                $this->relationships->saveProvision($relationship->id, $stored);
            }
            $this->withdrawal->withdraw($actor, $relationship->id);

            return new DefaultRoleOutcome(! $already, $already ? $relationship->provisions : $this->with($relationship, $role, $stored));
        }

        $already = $current !== null && $current->decision === StoredRoleProvision::Granted;
        $fresh = ! $already || $recordGrantAnew;
        $stored = $current;
        if ($fresh) {
            $stored = new StoredRoleProvision(
                $role->value,
                StoredRoleProvision::Granted,
                RelationshipTime::now(),
                $actor->personId,
            );
            // Written before Access, so a refusal or an audit failure rolls the decision back with the grant.
            $this->relationships->saveProvision($relationship->id, $stored);
        }
        ($this->grantRole)($actor, $relationship, $role);

        return new DefaultRoleOutcome($fresh, $fresh ? $this->with($relationship, $role, $stored) : $relationship->provisions);
    }

    private function current(RelationshipRecord $relationship, ProvisionableRole $role): ?StoredRoleProvision
    {
        foreach ($relationship->provisions as $provision) {
            if ($provision->role === $role->value) {
                return $provision;
            }
        }

        return null;
    }

    /**
     * @return list<StoredRoleProvision>
     */
    private function with(RelationshipRecord $relationship, ProvisionableRole $role, ?StoredRoleProvision $provision): array
    {
        if ($provision === null) {
            return $relationship->provisions;
        }

        return [...$this->without($relationship, $role), $provision];
    }

    /**
     * @return list<StoredRoleProvision>
     */
    private function without(RelationshipRecord $relationship, ProvisionableRole $role): array
    {
        return array_values(array_filter(
            $relationship->provisions,
            static fn (StoredRoleProvision $provision): bool => $provision->role !== $role->value,
        ));
    }
}
