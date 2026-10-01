<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * The read side of the Person registry: People as another module may see them, which is an id and a display name
 * (`PersonSummary`). A port (Identity's tables stay Identity's), read-only, and it decides nothing about who may
 * look: whoever exposes it must have authorized the caller first, because Identity cannot ask Access.
 *
 * Deliberately narrower than `AccountDirectory`. It knows nothing about Accounts, so it cannot match or reveal a
 * login email, an Account's status or any security state: those stay behind `identity.accounts.view` (ADR 0034).
 */
interface PeopleDirectory
{
    /** The query arrives already bounded. */
    public function search(PeopleQuery $query): PeoplePage;

    /**
     * People whose display name equals this (already trimmed, non-empty) name, ignoring case, by id, at most `$limit`.
     *
     * @return list<PersonSummary>
     */
    public function named(string $name, int $limit): array;
}
