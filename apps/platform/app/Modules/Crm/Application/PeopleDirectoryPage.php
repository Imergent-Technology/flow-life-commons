<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

/** One page of the People directory, and enough to page through the rest. */
final readonly class PeopleDirectoryPage
{
    /** @param  list<PersonListing>  $people */
    public function __construct(
        public array $people,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
