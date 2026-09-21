<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain;

use App\Shared\Domain\PersonId;

/**
 * Persistence of active role assignments.
 *
 * A row's existence is an active grant, so removal is a plain delete and history is the audit
 * trail's job. Callers that remove administrator authority must hold the lock taken by
 * lockHoldersOf() for the rest of their transaction (see Access\Application\AdministratorContinuity):
 * this port is the persistence, not the safety rule.
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
     * The CURRENT assignments of several people in ONE query, for a page of the Account list. Keyed by person id,
     * oldest first within each, and every requested person has an entry (an empty list if they hold nothing).
     *
     * @param  list<PersonId>  $personIds
     * @return array<string, list<RoleAssignment>>
     */
    public function forPeople(array $personIds): array;

    /**
     * @throws RoleAlreadyAssigned the Person already holds this role
     */
    public function add(RoleAssignment $assignment): void;

    /**
     * Deletes the Person's assignment of the role. False when there was none.
     */
    public function remove(PersonId $personId, string $roleKey): bool;

    /**
     * The people who currently hold the role, in a stable order. The key is matched EXACTLY:
     * MariaDB compares VARCHAR case-insensitively and PostgreSQL does not, and a wrongly-cased
     * stored key grants nothing, so it must not count as a holder on either engine.
     *
     * @return list<PersonId>
     */
    public function holdersOf(string $roleKey): array;

    /**
     * As holdersOf, but takes row locks (SELECT ... FOR UPDATE, ordered by id) that are held
     * until the caller's transaction ends, and reads the latest COMMITTED state rather than a
     * transaction snapshot. Must be called inside a transaction; it is what serialises
     * everything that can remove administrator authority.
     *
     * @return list<PersonId>
     */
    public function lockHoldersOf(string $roleKey): array;
}
