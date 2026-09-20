<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\RoleAlreadyAssigned;
use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Application\PersonExists;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Grants a system role to a Person on behalf of an authenticated Actor.
 *
 * - **Authorization is not optional.** The Actor must hold `access.roles.assign`, decided by the
 *   real Authorizer against current state. There is no flag, parameter or overload that skips
 *   it; the administrator bootstrap is a separate use case with its own, different, root of
 *   trust rather than a bypass here.
 * - Only a Role from the code-owned catalog can be named, and roles stay inside Access: callers
 *   outside this module never see one.
 * - **Idempotent.** Granting a role already held changes nothing, succeeds, and records
 *   nothing: an audit event says a security-relevant state changed, so it is written only when
 *   one did. The original grant (and who made it) is left as it was.
 * - The audit event is written in the same transaction as the assignment (ADR 0019): both
 *   commit or neither does.
 * - Provenance: `granted_by_account_id` is the acting Account.
 */
final readonly class GrantRole
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private RoleAssignmentRepository $assignments,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied the Actor may not assign roles
     * @throws UnknownPerson there is no such Person
     */
    public function __invoke(Actor $actor, PersonId $person, Role $role): RoleMutation
    {
        ($this->authorize)($actor, Capability::AssignRoles);

        if (! ($this->personExists)($person)) {
            throw new UnknownPerson;
        }

        try {
            return $this->database->transaction(function () use ($actor, $person, $role): RoleMutation {
                foreach ($this->assignments->forPerson($person) as $existing) {
                    if ($existing->roleKey === $role->value) {
                        return RoleMutation::Unchanged;
                    }
                }

                $this->assignments->add(RoleAssignment::grant(
                    $person, $role->value, $actor->accountId, DateTimeImmutable::createFromInterface(now()),
                ));
                ($this->record)(
                    AccessEvent::RoleGranted->value, SecurityEventOutcome::Success,
                    $actor, $person, null, null, null, ['role' => $role->value],
                );

                return RoleMutation::Changed;
            }, 3);
        } catch (RoleAlreadyAssigned) {
            // Lost a race with an identical grant. The unique constraint held; this one is a no-op.
            return RoleMutation::Unchanged;
        }
    }
}
