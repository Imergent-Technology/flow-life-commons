<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/** One page of the Account list as an operator sees it. */
final readonly class ManagedAccountsPage
{
    /** @param  list<AccountView>  $views */
    public function __construct(
        public array $views,
        public int $page,
        public int $perPage,
        public int $total,
        public int $lastPage,
    ) {}
}
