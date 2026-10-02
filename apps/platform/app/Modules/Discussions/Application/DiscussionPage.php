<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

/** One page of discussions, most recently active first, and enough to page through the rest. */
final readonly class DiscussionPage
{
    /** @param  list<DiscussionView>  $discussions */
    public function __construct(
        public array $discussions,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
