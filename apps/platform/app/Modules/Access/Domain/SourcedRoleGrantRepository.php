<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain;

use App\Shared\Domain\PersonId;

/**
 * Persistence of active sourced role grants (ADR 0038, K4).
 *
 * Removal is a plain delete. Callers that decide whether a grant should exist lock the
 * source's rows first (`lockForSource`) and hold that lock until their transaction ends.
 */
interface SourcedRoleGrantRepository
{
    /**
     * The Person's current sourced grants, oldest first. Never cached.
     *
     * @return list<SourcedRoleGrant>
     */
    public function forPerson(PersonId $personId): array;

    /**
     * @param  list<PersonId>  $personIds
     * @return array<string, list<SourcedRoleGrant>>
     */
    public function forPeople(array $personIds): array;

    /**
     * Every grant of one source type, oldest first. Includes keys the catalog no longer knows.
     *
     * @return list<SourcedRoleGrant>
     */
    public function forSourceType(string $sourceType): array;

    /**
     * @param  list<array{0: string, 1: string}>  $sources  each pair is source type, source id
     * @return list<SourcedRoleGrant>
     */
    public function forSources(array $sources): array;

    public function findBySourceAndRole(string $sourceType, string $sourceId, string $roleKey): ?SourcedRoleGrant;

    /**
     * The grant of this source and role, locked until the caller's transaction ends.
     * The key is matched exactly. Must be called inside a transaction.
     */
    public function lockBySourceAndRole(string $sourceType, string $sourceId, string $roleKey): ?SourcedRoleGrant;

    /**
     * Blocks until this source's grant or withdrawal can proceed, then returns.
     * Held until the caller's transaction ends. Must be called inside a transaction.
     *
     * PostgreSQL does not make `SELECT … FOR UPDATE` wait for a row another transaction
     * has inserted but not committed. A transaction-scoped advisory lock on the source
     * closes that gap. MariaDB already waits on the unique index, so no extra lock is taken.
     */
    public function lockSource(string $sourceType, string $sourceId): void;

    /**
     * Every grant of this source, locked in id order until the caller's transaction ends.
     *
     * @return list<SourcedRoleGrant>
     */
    public function lockForSource(string $sourceType, string $sourceId): array;

    /**
     * @throws SourcedGrantAlreadyRecorded
     */
    public function add(SourcedRoleGrant $grant): void;

    public function delete(SourcedRoleGrantId $id): void;

    /**
     * People who hold one of these role keys through a sourced grant. Keys are matched exactly.
     *
     * @param  list<string>  $roleKeys
     * @return list<PersonId>
     */
    public function personIdsHoldingRoles(array $roleKeys): array;
}
