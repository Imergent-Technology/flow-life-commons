<?php

declare(strict_types=1);

namespace App\Modules\Membership\Domain;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * Persistence of membership grants: a non-deleting history with explicit one-way revocation.
 *
 * Deliberately has no generic save(): the only legal mutation after creation is revoke(), so
 * that is the only way a stored row can change. There is no delete() and no update-everything
 * operation that could rewrite `startsAt`, `source` or any other immutable field.
 */
interface MembershipGrantRepository
{
    public function add(MembershipGrant $grant): void;

    public function find(MembershipGrantId $id): ?MembershipGrant;

    /**
     * A Person's grants, oldest first (by `startsAt`, then `id`), revoked or not. Never cached.
     *
     * @return list<MembershipGrant>
     */
    public function forPerson(PersonId $personId): array;

    /**
     * Race-safe one-way revocation: one `UPDATE ... SET revoked_at = ?, revoked_by_account_id = ?
     * WHERE id = ? AND revoked_at IS NULL` (ADR 0025's conditional-update precedent). Returns
     * true iff THIS call performed the revocation; false means a revocation was already
     * committed, by this caller or another — the caller distinguishes "already revoked" from
     * "no such grant" itself, with find(), since this method alone cannot tell them apart.
     */
    public function revoke(MembershipGrantId $id, AccountId $revokedBy, DateTimeImmutable $now): bool;

    /**
     * A page of distinct Persons who hold at least one grant, ordered by PersonId, plus the total
     * distinct count: the admin list's pagination. The unit is a Person, not a grant row, and it is
     * every Person who has EVER held a grant — one whose only grants are expired or revoked is still
     * counted and still paged in. There is deliberately no unbounded "every grant" read: the list is
     * always a bounded page.
     *
     * @return array{personIds: list<PersonId>, total: int}
     */
    public function personIdsPage(int $page, int $perPage): array;

    /**
     * The complete grant history of each of these Persons, oldest first, in one query rather than
     * one per Person.
     *
     * @param  list<PersonId>  $personIds
     * @return array<string, list<MembershipGrant>> keyed by PersonId value
     */
    public function forPeople(array $personIds): array;
}
