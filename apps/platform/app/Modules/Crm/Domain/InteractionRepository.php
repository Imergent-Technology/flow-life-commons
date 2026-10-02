<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use App\Shared\Domain\PersonId;

/**
 * Interaction persistence. No cross-row invariant is at stake (no uniqueness, no "exactly one"), so nothing here needs
 * the Person's profile-row lock: each write is one statement on one row.
 */
interface InteractionRepository
{
    public function find(InteractionId $id): ?Interaction;

    /**
     * One page of a Person's interactions, newest first: when it happened, then when it was recorded, then id, all
     * descending, so the order is total and a page boundary is never ambiguous.
     *
     * @return list<Interaction>
     */
    public function page(PersonId $personId, int $page, int $perPage): array;

    public function count(PersonId $personId): int;

    public function add(Interaction $interaction): void;

    /** Writes the changeable fields (kind, body, occurred_at, last editor, update time). */
    public function save(Interaction $interaction): void;

    /** Removes the Person's interaction; false when there is no such interaction for that Person. */
    public function remove(PersonId $personId, InteractionId $id): bool;
}
