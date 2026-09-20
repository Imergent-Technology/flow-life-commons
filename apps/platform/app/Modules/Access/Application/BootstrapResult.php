<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\IssuedInvitation;

/**
 * What a committed bootstrap produced. Holding one means the transaction has already committed, so
 * the invitation secret inside it is safe to show. It is shown once and cannot be recovered.
 */
final readonly class BootstrapResult
{
    public function __construct(
        public IssuedInvitation $invitation,
        public bool $recovery,
    ) {}
}
