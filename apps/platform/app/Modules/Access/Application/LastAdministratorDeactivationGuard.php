<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountDeactivationGuard;
use App\Modules\Identity\Application\AccountDeactivationRefused;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;

/**
 * Access's implementation of Identity's deactivation guard (ADR 0020): disabling the Account of
 * the last active administrator is refused.
 *
 * It adds no rule of its own. It hands the question to AdministratorContinuity, the same
 * authority RevokeRole uses, so that a revoke and a disable racing each other take the same
 * lock in the same order and protect one invariant, not two look-alikes.
 */
final readonly class LastAdministratorDeactivationGuard implements AccountDeactivationGuard
{
    public function __construct(private AdministratorContinuity $continuity) {}

    public function assertMayDeactivate(AccountId $account, PersonId $person): void
    {
        try {
            $this->continuity->assertMayRemoveAdministratorAuthorityOf($person);
        } catch (LastAdministratorRequired $e) {
            throw new AccountDeactivationRefused($e->getMessage());
        }
    }
}
