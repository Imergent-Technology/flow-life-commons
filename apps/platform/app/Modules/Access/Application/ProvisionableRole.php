<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/**
 * Roles a relationship source may grant (ADR 0038, K2). In G10 this is exactly
 * Guardian Initiate, whose only capability is `console.access`.
 *
 * Relationships hold a case of this enum and never a role key. Access turns it into a Role.
 */
enum ProvisionableRole: string
{
    case GuardianInitiate = 'guardian-initiate';

    public function role(): Role
    {
        return match ($this) {
            self::GuardianInitiate => Role::GuardianInitiate,
        };
    }
}
