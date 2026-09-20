<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain;

use App\Shared\Domain\PersonId;

/**
 * Persistence of active role assignments.
 *
 * Deliberately small. It can read a Person's assignments and add one. There is no removal
 * here yet: revoking a role must honour the last-active-administrator invariant and be
 * audited, and both arrive together with the administration workflow, so an executable
 * revoke belongs to that phase and not to this port.
 */
interface RoleAssignmentRepository
{
    /**
     * The Person's CURRENT assignments, read fresh on every call, oldest first. Never cached.
     *
     * @return list<RoleAssignment>
     */
    public function forPerson(PersonId $personId): array;

    /**
     * @throws RoleAlreadyAssigned the Person already holds this role
     */
    public function add(RoleAssignment $assignment): void;
}
