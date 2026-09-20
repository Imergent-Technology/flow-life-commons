<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** What the Console may show about an Account's second factor. Nothing about the factor itself. */
final readonly class MfaStatus
{
    public function __construct(
        public bool $enrolled,
        public int $recoveryCodesRemaining,
    ) {}
}
