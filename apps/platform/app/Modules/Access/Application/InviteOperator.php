<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\DeliverInvitation;
use App\Modules\Identity\Application\EmailAlreadyInUse;
use App\Modules\Identity\Application\InvalidInvitationDetails;
use App\Modules\Identity\Application\InvitationDetails;
use App\Modules\Identity\Application\InviteAccount;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;

/**
 * An operator invites a new person to the Console (ADR 0024): a Person, an INVITED Account with no password, a
 * single-use expiring invitation, and optionally initial role assignments, then the invitation mailed to the address.
 *
 * - **Authorized first**: `identity.invitations.issue`, and `access.roles.assign` too when roles are chosen. Both are
 *   decided from current state before anything is written.
 * - **One transaction** for everything that is state: the Person, the Account, the invitation, each role grant and
 *   every audit event. If any of them fails NONE remains, and nothing was mailed. Roles go through GrantRole, so they
 *   are authorized, audited (`role.granted`) and idempotent exactly as they are anywhere else.
 * - **The message is sent AFTER the commit, and outside it.** The invitation is an EMAIL one (accepting it shows the
 *   mailbox was reached). If the mail system refuses, the Account and its invitation stay committed, the caller is
 *   told (`Failed`), `invitation.delivery_failed` is recorded, and the remedy is ReissueOperatorInvitation.
 * - The secret is never returned: not here, not to the HTTP layer, not to the Console.
 * - Nothing makes activation depend on a role: an invited Account with none simply cannot enter the Console.
 */
final readonly class InviteOperator
{
    public function __construct(
        private AuthorizeAction $authorize,
        private InviteAccount $invite,
        private GrantRole $grant,
        private DeliverInvitation $deliver,
        private AccountViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  list<string>  $assignments  role keys from the catalog, or none
     *
     * @throws AccessDenied
     * @throws InvalidInvitationDetails
     * @throws UnknownRole a key is not in the catalog
     * @throws EmailAlreadyInUse
     */
    public function __invoke(Actor $actor, string $email, string $displayName, array $assignments = []): OperatorInvitation
    {
        ($this->authorize)($actor, Capability::IssueInvitations);

        $roles = [];
        foreach ($assignments as $key) {
            $roles[$key] = Role::tryFrom($key) ?? throw new UnknownRole;
        }
        if ($roles !== []) {
            ($this->authorize)($actor, Capability::AssignRoles);
        }
        $details = InvitationDetails::from($email, $displayName);

        $issued = $this->database->transaction(function () use ($actor, $details, $roles) {
            $issued = $this->invite->byEmail($details, $actor);
            foreach ($roles as $role) {
                ($this->grant)($actor, $issued->personId, $role);
            }

            return $issued;
        });

        $delivery = ($this->deliver)($issued, $actor, 'issued');

        return new OperatorInvitation($this->views->of($issued->accountId), $delivery);
    }
}
