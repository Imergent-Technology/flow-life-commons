<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountNotFound;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/** One Account, as an operator inspects it. Needs `identity.accounts.view`. */
final readonly class ShowManagedAccount
{
    public function __construct(
        private AuthorizeAction $authorize,
        private AccountViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws AccountNotFound
     */
    public function __invoke(Actor $actor, AccountId $account): AccountView
    {
        ($this->authorize)($actor, Capability::ViewAccounts);

        return $this->views->of($account);
    }
}
