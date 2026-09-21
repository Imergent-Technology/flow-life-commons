<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountDeactivationRefused;
use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\DisableAccount;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/**
 * An operator takes an Account out of service. Needs `identity.accounts.manage`. Everything about HOW is Identity's
 * DisableAccount, unchanged: the deactivation guard chain (so the last active administrator cannot be disabled), the
 * lock and re-read, ending every session, and the `account.disabled` event. The Person, assignments and history are
 * untouched. Disabling an Account that is already disabled succeeds and changes nothing.
 */
final readonly class DisableManagedAccount
{
    public function __construct(
        private AuthorizeAction $authorize,
        private DisableAccount $disable,
        private AccountViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws AccountNotFound
     * @throws LastAdministratorRequired
     */
    public function __invoke(Actor $actor, AccountId $account): AccountView
    {
        ($this->authorize)($actor, Capability::ManageAccounts);

        try {
            ($this->disable)($account, $actor);
        } catch (AccountDeactivationRefused) {
            // The one guard on the chain is this module's: the last active administrator.
            throw new LastAdministratorRequired;
        }

        return $this->views->of($account);
    }
}
