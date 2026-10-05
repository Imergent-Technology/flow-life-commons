<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

/**
 * A file the caller may have, opened for streaming: the name to offer it under (the sanitised original name, never the storage key),
 * the allowlisted media type stored for it, the size the store reports now, and whether it may be shown `inline` when asked. The
 * HTTP edge streams `stream` and closes it.
 */
final readonly class FileDownload
{
    /** @param  resource  $stream */
    public function __construct(
        public string $filename,
        public string $mediaType,
        public int $size,
        public mixed $stream,
        public bool $opensInline,
    ) {}
}
