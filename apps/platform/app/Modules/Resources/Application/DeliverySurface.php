<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceSet;

/**
 * Which audiences a delivery surface serves (ADR 0037, decision 42). The Guardian Console serves the `guardian` audience, to an
 * Actor who holds `resources.view`, and only that: a Guardian who is also a Member does not receive Member content there, and
 * Member eligibility (which belongs to Membership) is asked only by a surface that delivers to Members, which does not exist yet.
 * There is no Guardian business relationship (ADR 0036, decision 7), so for now Guardian eligibility is Access's answer, asked as
 * the capability the use case checks.
 */
final class DeliverySurface
{
    public static function guardianConsole(): AudienceSet
    {
        return AudienceSet::of(Audience::Guardian);
    }
}
