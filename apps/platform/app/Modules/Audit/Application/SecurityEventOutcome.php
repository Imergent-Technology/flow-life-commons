<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

/** What became of the operation an event describes. */
enum SecurityEventOutcome: string
{
    /** It happened. */
    case Success = 'success';

    /** It was attempted and did not succeed. */
    case Failure = 'failure';

    /** The platform refused to proceed (rate limit, expired session). */
    case Blocked = 'blocked';
}
