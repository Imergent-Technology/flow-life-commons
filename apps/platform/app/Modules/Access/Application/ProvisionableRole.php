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

    /**
     * The provisionable role a STORED role key names, or null for any other key: unknown, retired, wrongly written, or a
     * real role a relationship may not confer (`platform_administrator`, `guardian-full`). The read side of a sourced
     * grant goes through this, so it honours exactly what the write side admits.
     */
    public static function fromRoleKey(string $roleKey): ?self
    {
        foreach (self::cases() as $provisionable) {
            if ($provisionable->role()->value === $roleKey) {
                return $provisionable;
            }
        }

        return null;
    }

    /**
     * The stored role keys a sourced grant could hold to confer the capability. Empty when no provisionable role grants it.
     *
     * @return list<string>
     */
    public static function roleKeysGranting(Capability $capability): array
    {
        $keys = [];
        foreach (self::cases() as $provisionable) {
            if ($provisionable->role()->grants($capability)) {
                $keys[] = $provisionable->role()->value;
            }
        }

        return $keys;
    }

    /** The words an operator sees. Relationships ask for this and never for the role's capabilities. */
    public function displayName(): string
    {
        return $this->role()->displayName();
    }
}
