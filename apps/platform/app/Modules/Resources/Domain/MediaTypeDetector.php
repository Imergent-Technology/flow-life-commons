<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * What a file IS, judged from its bytes by the server (ADR 0037, decision 64), never from what the client said it was. The answer
 * is a media type, lower-case, compared with the allowlist (`FileKind`); a file the detector cannot place is reported as
 * `application/octet-stream`, which no kind accepts.
 */
interface MediaTypeDetector
{
    public function detect(string $path): string;
}
