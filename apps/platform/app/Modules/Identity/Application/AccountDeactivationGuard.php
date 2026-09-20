<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;

/**
 * Identity's veto point over anything that takes an Account out of service (ADR 0020).
 *
 * Identity owns Account state, so it owns this extension point and consults every registered
 * guard inside DisableAccount, and inside every future closure or anonymisation path. Other
 * modules implement it and register themselves from their own provider by tagging the
 * implementation with TAG. Identity never names them, which is what keeps the dependency
 * running one way (Access -> Identity) and the module graph acyclic.
 *
 * Guards run INSIDE the transaction that makes the change, before it does anything else, so a
 * guard that takes locks holds them until that change commits or rolls back. They must refuse
 * by throwing AccountDeactivationRefused, and must not modify anything themselves.
 */
interface AccountDeactivationGuard
{
    /** The container tag under which implementations are registered. */
    public const string TAG = 'identity.account-deactivation-guards';

    /**
     * @throws AccountDeactivationRefused
     */
    public function assertMayDeactivate(AccountId $account, PersonId $person): void;
}
