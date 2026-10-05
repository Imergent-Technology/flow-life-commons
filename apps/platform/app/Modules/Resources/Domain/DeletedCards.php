<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * What deleting a Pack's Cards removed from business state: how many Cards, and the storage keys of the asset rows deleted with
 * them, whose files are removed after the deletion commits. `files()` is the number of asset rows removed, which is what
 * `resource.pack_deleted` records as `files_deleted` (ADR 0037, decision 55).
 */
final readonly class DeletedCards
{
    /** @param  list<StorageKey>  $storageKeys */
    public function __construct(public int $cards, public array $storageKeys) {}

    public function files(): int
    {
        return count($this->storageKeys);
    }
}
