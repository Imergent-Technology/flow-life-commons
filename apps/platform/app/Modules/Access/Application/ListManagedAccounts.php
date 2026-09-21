<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountDirectory;
use App\Modules\Identity\Application\AccountSearch;
use App\Shared\Domain\Actor;

/**
 * A modest page of Accounts for the operator's list. Needs `identity.accounts.view`, decided from current persisted
 * state before anything is read. Read-only: no lock, no audit (a list is not a security-relevant change).
 */
final readonly class ListManagedAccounts
{
    public function __construct(
        private AuthorizeAction $authorize,
        private AccountDirectory $directory,
        private AccountViews $views,
    ) {}

    /**
     * @throws AccessDenied
     */
    public function __invoke(Actor $actor, AccountSearch $search): ManagedAccountsPage
    {
        ($this->authorize)($actor, Capability::ViewAccounts);

        $page = $this->directory->search($search);

        return new ManagedAccountsPage($this->views->many($page->accounts), $page->page, $page->perPage, $page->total, $page->lastPage());
    }
}
