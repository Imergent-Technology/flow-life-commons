<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** What EnableAccount did. Unlike a disable, enabling an Account that is not disabled is refused, not ignored. */
enum ReactivationOutcome
{
    case Enabled;
    case NotDisabled;
}
