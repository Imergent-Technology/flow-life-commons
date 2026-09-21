<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/**
 * A named bundle of capabilities, assignable to a Person (ADR 0017). Code-owned, and the
 * `role -> capabilities` mapping below is the ONLY place it exists.
 *
 * **Roles are internal to Access.** They are how capabilities are bundled and assigned,
 * never how anything is authorized: business code asks whether an Actor holds a
 * Capability and must never inspect a role name. `if (role === 'guardian')` is a defect,
 * and an architecture test forbids anything outside Access from naming this type or the
 * role keys.
 */
enum Role: string
{
    /** Resolves to EVERY capability, present and future, and is the only role that does. */
    case PlatformAdministrator = 'platform_administrator';

    /** The Console's ordinary user: may use it, and nothing more yet. */
    case Guardian = 'guardian';

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            // Derived from the catalog, not listed, so adding a capability grants it to the
            // administrator without editing this definition. Nothing else works this way.
            self::PlatformAdministrator => Capability::cases(),
            self::Guardian => [Capability::ConsoleAccess],
        };
    }

    /** What an operator sees in the role list. Access owns the words; the Console renders whatever it is told. */
    public function displayName(): string
    {
        return match ($this) {
            self::PlatformAdministrator => 'Platform administrator',
            self::Guardian => 'Guardian',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PlatformAdministrator => 'Everything the platform can do, including administering other people\'s access.',
            self::Guardian => 'May use the Guardian Console. Nothing more yet.',
        };
    }

    public function grants(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }
}
