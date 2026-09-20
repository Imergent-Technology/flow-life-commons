<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** Both outcomes are success: disabling an Account that is already disabled changes nothing. */
enum DeactivationOutcome
{
    case Disabled;
    case AlreadyDisabled;
}
