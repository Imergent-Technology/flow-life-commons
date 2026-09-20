<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;

/**
 * Revokes a system role from a Person on behalf of an authenticated Actor.
 *
 * - Authorization is not optional (see GrantRole): `access.roles.assign`, from the real
 *   Authorizer, decided BEFORE the transaction opens.
 * - **Revoking `platform_administrator` goes through AdministratorContinuity**, the one
 *   authority for the last-administrator invariant, which locks before it decides. Nothing here
 *   counts administrators itself.
 * - Idempotent: revoking a role not held succeeds, changes nothing and records nothing.
 * - The revocation and its audit event commit together or not at all. Authorization history
 *   lives in the audit trail; the deleted row leaves no trace of its own.
 */
final readonly class RevokeRole
{
    public function __construct(
        private AuthorizeAction $authorize,
        private AdministratorContinuity $continuity,
        private RoleAssignmentRepository $assignments,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied the Actor may not assign roles
     * @throws LastAdministratorRequired this would leave no active administrator
     */
    public function __invoke(Actor $actor, PersonId $person, Role $role): RoleMutation
    {
        ($this->authorize)($actor, Capability::AssignRoles);

        return $this->database->transaction(function () use ($actor, $person, $role): RoleMutation {
            if ($role === Role::PlatformAdministrator) {
                $this->continuity->assertMayRemoveAdministratorAuthorityOf($person);
            }

            if (! $this->assignments->remove($person, $role->value)) {
                return RoleMutation::Unchanged;
            }

            ($this->record)(
                AccessEvent::RoleRevoked->value, SecurityEventOutcome::Success,
                $actor, $person, null, null, null, ['role' => $role->value],
            );

            return RoleMutation::Changed;
        }, 3);
    }
}
