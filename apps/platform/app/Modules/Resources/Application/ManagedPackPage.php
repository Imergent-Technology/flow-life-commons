<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

/** One page of Packs for management, and enough to page through the rest. */
final readonly class ManagedPackPage
{
    /** @param  list<ManagedPackView>  $packs */
    public function __construct(public array $packs, public int $page, public int $perPage, public int $total) {}

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
