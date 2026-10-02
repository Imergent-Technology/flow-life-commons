<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

/** One page of a Person's interactions, newest first, and enough to page through the rest. */
final readonly class InteractionPage
{
    /** @param  list<InteractionView>  $interactions */
    public function __construct(
        public array $interactions,
        public int $page,
        public int $perPage,
        public int $total,
    ) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
