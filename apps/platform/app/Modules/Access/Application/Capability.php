<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/**
 * A named permission to attempt an action (ADR 0017). Code-owned: there is no
 * `permissions` table, so a capability nothing checks cannot exist as a row, and a code
 * reference to a missing one cannot exist at all.
 *
 * This is Access's public vocabulary: business code asks for a Capability, never for a
 * role. It is deliberately minimal. A capability is added when the functionality that
 * checks it exists, never speculatively for a module that does not.
 *
 * The value is dotted lowercase, `area.thing.verb`, and is also the Laravel Gate ability
 * name, which is derived from this enum rather than being a second catalog.
 */
enum Capability: string
{
    /** May use the Guardian Console. Every Console user's role carries it. */
    case ConsoleAccess = 'console.access';

    /** May grant and revoke role assignments. The narrowest capability the administration workflow needs. */
    case AssignRoles = 'access.roles.assign';

    /** May list and inspect Accounts (and see the role catalog): administration's read side. Changes nothing. */
    case ViewAccounts = 'identity.accounts.view';

    /**
     * May disable and re-enable an Account, and send its holder the normal password-reset email. Ending someone's access is
     * a materially bigger power than seeing it; sending the reset email gives the operator nothing (no password, token or link).
     */
    case ManageAccounts = 'identity.accounts.manage';

    /** May invite a new operator, and issue a fresh invitation for one who is still invited. */
    case IssueInvitations = 'identity.invitations.issue';

    /** May reset ANOTHER Account's second factor when its owner has lost the authenticator and every recovery code. */
    case RecoverMfa = 'identity.mfa.recover';

    /** May list and inspect membership state and grant history (ADR 0028). Changes nothing. */
    case ViewMembershipRecords = 'membership.records.view';

    /** May register a Person for membership purposes and create or revoke membership access grants (ADR 0028). */
    case ManageMembershipRecords = 'membership.records.manage';

    /** May see the People directory and what CRM holds about each Person: contact methods, profile, tags (ADR 0034). Changes nothing. */
    case ViewPeople = 'crm.people.view';

    /**
     * May change what CRM holds about a Person, register a Person, manage tags, and correct a Person's display name (ADR 0034).
     *
     * For ROUTINE CRM maintenance only, which is why its mutations ask for no recent verification: the step-up exemption in
     * the administration route table is this capability and nothing else. A materially security-sensitive operation (merging
     * Persons, a bulk export, a destructive Account or security action) must get its own capability, protected as such, and
     * must not be put behind this one to inherit the exemption.
     */
    case ManagePeople = 'crm.people.manage';
}
