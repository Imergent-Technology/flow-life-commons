<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

/**
 * An uploaded file as the HTTP edge received it, before anything has judged it: where PHP put its bytes (a temporary file outside
 * the store) and the name the client gave it. Neither is trusted. The client's claimed media type is deliberately not carried at all:
 * nothing may consult it (ADR 0037, decision 64).
 */
final readonly class IncomingFile
{
    public function __construct(public string $path, public string $clientName) {}
}
