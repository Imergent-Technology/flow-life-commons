<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/** One file found directly in the store, by its raw name (not necessarily a key this store wrote) and when it was last modified. */
final readonly class StoredObject
{
    public function __construct(public string $name, public int $lastModified) {}
}
