<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/**
 * A named bundle of capabilities, assignable to a Person (ADR 0017). Code-owned, and the
 * `role -> capabilities` mapping below is the ONLY place it exists.
 *
 * **Roles are internal to Access.** They are how capabilities are bundled and assigned,
 * never how anything is authorized: business code asks whether an Actor holds a
 * Capability and must never inspect a role name. `if (role === 'guardian-full')` is a defect,
 * and an architecture test forbids anything outside Access from naming this type or the
 * role keys.
 *
 * The five roles are additive and independent (ADR 0038, A3). No role contains another,
 * and none of them establishes an organizational relationship. The relationship type and
 * the Resource audience stay `guardian`; only this catalog's former `guardian` key moved,
 * to `guardian-full`. `Role::tryFrom('guardian')` is null.
 */
enum Role: string
{
    /** Resolves to EVERY capability, present and future, and is the only role that does. */
    case PlatformAdministrator = 'platform_administrator';

    /**
     * Today's former `guardian` bundle (ADR 0038, A3). It may see Guardians and see and manage
     * Volunteers. It may not manage Guardians: recognition is a separate capability, held by the
     * platform administrator until a role is given it deliberately.
     */
    case GuardianFull = 'guardian-full';

    /**
     * Initially the same bundle as `guardian-full`. It is a separate role, not a rank: nothing
     * compares the two, and it has no approval, financial, subscription or administrative authority.
     */
    case GuardianSenior = 'guardian-senior';

    /** Console admission only. It is not a Guardian relationship and it grants no Resource read. */
    case GuardianInitiate = 'guardian-initiate';

    /** Console admission only, for a person who is not being described as a Guardian at all. */
    case ConsoleParticipant = 'console-participant';

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            // Derived from the catalog, not listed, so adding a capability grants it to the
            // administrator without editing this definition. Nothing else works this way.
            self::PlatformAdministrator => Capability::cases(),
            self::GuardianFull, self::GuardianSenior => self::organizationalBundle(),
            self::GuardianInitiate, self::ConsoleParticipant => [Capability::ConsoleAccess],
        };
    }

    /** What an operator sees in the role list. Access owns the words; the Console renders whatever it is told. */
    public function displayName(): string
    {
        return match ($this) {
            self::PlatformAdministrator => 'Platform administrator',
            self::GuardianFull => 'Guardian',
            self::GuardianSenior => 'Senior Guardian',
            self::GuardianInitiate => 'Guardian Initiate',
            self::ConsoleParticipant => 'Console Participant',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PlatformAdministrator => 'Everything the platform can do, including administering other people\'s access.',
            self::GuardianFull => 'May use the Guardian Console, see and manage People (contacts, tags), take part in Guardian discussions, manage Resources, see Guardians, and see and manage Volunteers. May not manage Guardians.',
            self::GuardianSenior => 'May use the Guardian Console, see and manage People (contacts, tags), take part in Guardian discussions, manage Resources, see Guardians, and see and manage Volunteers. Initially the same as Guardian. May not manage Guardians, assign roles, or administer the platform.',
            self::GuardianInitiate => 'May use the Guardian Console. May not see or manage People, discussions, Resources, Guardians or Volunteers, and may not administer the platform.',
            self::ConsoleParticipant => 'May use the Guardian Console. May not see or manage People, discussions, Resources, Guardians or Volunteers, and may not administer the platform.',
        };
    }

    public function grants(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * The bundle `guardian-full` and `guardian-senior` share today. One list, so they cannot
     * drift by a missed edit. They are still two roles: holding one does not hold the other.
     *
     * @return list<Capability>
     */
    private static function organizationalBundle(): array
    {
        return [
            Capability::ConsoleAccess, Capability::ViewPeople, Capability::ManagePeople,
            // Guardian Discussions access is an accepted owner decision (ADR 0035): both capabilities, listed, not derived.
            Capability::ViewDiscussions, Capability::ParticipateInDiscussions,
            // Resources access is an accepted owner decision (ADR 0037): Guardians are peers and organizational content has no
            // owner, so every Guardian may manage it. Both capabilities, listed, not derived.
            Capability::ViewResources, Capability::ManageResources,
            // Organizational relationships (ADR 0038, A3), listed, not derived. Managing Guardians is not included:
            // `guardians.manage` stays with the platform administrator until a role is given it on purpose.
            Capability::ViewGuardians, Capability::ViewVolunteers, Capability::ManageVolunteers,
        ];
    }
}
