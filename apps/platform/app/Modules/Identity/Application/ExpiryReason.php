<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

enum ExpiryReason: string
{
    /** now >= authenticated_at + the absolute lifetime. */
    case AbsoluteLifetime = 'absolute_lifetime';

    /** An authenticated session with no authenticated_at: fail safe rather than grant an unlimited lifetime. */
    case MissingAuthenticatedAt = 'missing_authenticated_at';

    /** authenticated_at is not a usable instant (wrong type, or in the future). */
    case InvalidAuthenticatedAt = 'invalid_authenticated_at';
}
