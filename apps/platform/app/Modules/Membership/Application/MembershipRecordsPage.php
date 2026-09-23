<?php

declare(strict_types=1);

namespace App\Modules\Membership\Application;

/** One page of the admin membership list: distinct Persons, not raw grant rows (Package 5). */
final readonly class MembershipRecordsPage
{
    /** @param  list<MembershipRecord>  $records */
    public function __construct(
        public array $records,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
