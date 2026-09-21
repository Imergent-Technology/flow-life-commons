<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\EnableAccount;
use App\Modules\Identity\Application\ReactivationOutcome;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/**
 * An operator puts a disabled Account back into service. Needs `identity.accounts.manage`. It reopens the door and
 * nothing more: no session, no password, no role change, and no MFA bypass (Identity's EnableAccount).
 */
final readonly class EnableManagedAccount
{
    public function __construct(
        private AuthorizeAction $authorize,
        private EnableAccount $enable,
        private AccountViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws AccountNotFound
     * @throws AccountNotDisabled
     */
    public function __invoke(Actor $actor, AccountId $account): AccountView
    {
        ($this->authorize)($actor, Capability::ManageAccounts);

        if (($this->enable)($account, $actor) === ReactivationOutcome::NotDisabled) {
            throw new AccountNotDisabled;
        }

        return $this->views->of($account);
    }
}
