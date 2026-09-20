<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

enum AuthenticationStatus
{
    case Authenticated;

    /** The password was right, but a second factor must follow: no session yet (see PendingLogin). */
    case SecondFactorPending;

    case Failed;
    case Throttled;
}
