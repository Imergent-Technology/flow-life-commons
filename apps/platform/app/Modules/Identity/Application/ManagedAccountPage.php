<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** One page of Accounts, and enough to page through the rest. */
final readonly class ManagedAccountPage
{
    /** @param  list<ManagedAccount>  $accounts */
    public function __construct(
        public array $accounts,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
