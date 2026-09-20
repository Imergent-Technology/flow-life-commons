<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

enum AuthenticationStatus
{
    case Authenticated;
    case Failed;
    case Throttled;
}
