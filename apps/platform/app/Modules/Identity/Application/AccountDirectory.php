<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\AccountId;

/**
 * The read side of operator administration: Accounts as an operator may see them. A port (Identity's tables stay
 * Identity's), and read-only: nothing here changes anything, takes a lock or decides who may look. Whoever exposes
 * it must have authorized the caller first; Identity cannot ask Access.
 */
interface AccountDirectory
{
    public function search(AccountSearch $search): ManagedAccountPage;

    public function find(AccountId $id): ?ManagedAccount;
}
