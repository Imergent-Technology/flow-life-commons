<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/** A stored file opened for reading: the stream and the size it has on the store now. The reader closes the stream. */
final readonly class StoredFile
{
    /** @param  resource  $stream */
    public function __construct(public mixed $stream, public int $size) {}
}
