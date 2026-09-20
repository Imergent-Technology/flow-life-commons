<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** Where a request came from, as far as the audit trail cares. Both are client-supplied. */
final readonly class ClientContext
{
    public function __construct(
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}
}
