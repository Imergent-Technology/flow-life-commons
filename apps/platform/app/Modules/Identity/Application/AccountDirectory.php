<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;

/**
 * The read side of operator administration: Accounts as an operator may see them. A port (Identity's tables stay
 * Identity's), and read-only: nothing here changes anything, takes a lock or decides who may look. Whoever exposes
 * it must have authorized the caller first; Identity cannot ask Access.
 */
interface AccountDirectory
{
    public function search(AccountSearch $search): ManagedAccountPage;

    public function find(AccountId $id): ?ManagedAccount;

    /**
     * As `find`, but by the Person it belongs to (ADR 0015: at most one Account per Person, so this can never
     * return more than one). For a caller that starts from a Person, not an Account — Membership's admin surface,
     * first, deciding whether a Person may still be invited.
     */
    public function findByPersonId(PersonId $personId): ?ManagedAccount;
}
