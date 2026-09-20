<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** What a second-factor reset did. `changed` is false when there was nothing to reset (no authenticator, no codes). */
final readonly class MfaReset
{
    public function __construct(
        public bool $changed,
        public int $sessionsEnded = 0,
    ) {}
}
