<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\DeliverInvitation;
use App\Modules\Identity\Application\EmailAlreadyInUse;
use App\Modules\Identity\Application\InvalidInvitationDetails;
use App\Modules\Identity\Application\InviteAccountForPerson;
use App\Modules\Identity\Application\PersonHasAccount;
use App\Modules\Identity\Application\PersonNotFound;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;

/**
 * An operator invites a Person who already exists — most likely one Membership registered with no Account
 * (`RegisterPerson`, ADR 0028) — to a Commons Account, then the invitation mailed to the address (ADR 0032).
 *
 * This is the operator-facing sibling of `InviteOperator`, sharing its authority (`identity.invitations.issue`:
 * the same capability governs issuing any account invitation, existing person or not) and its delivery/response
 * shape, but composing a different Identity use case: `InviteAccountForPerson`, which creates no new Person. There
 * is no role assignment here — an invited Member holds no capability by default, unlike an invited operator.
 *
 * - **Authorized first**: `identity.invitations.issue`, decided from current state before anything is written.
 * - **The message is sent AFTER commit, and outside it**, exactly as `InviteOperator`: if delivery fails, the
 *   Account and its invitation stay committed, the caller is told (`Failed`), `invitation.delivery_failed` is
 *   recorded, and the remedy is `ReissueInvitation`/`ReissueOperatorInvitation`'s HTTP twin — the SAME reissue
 *   surface already exposed at `POST /admin/accounts/{account}/invitation`, since this produces an ordinary
 *   Account like any other.
 * - The secret is never returned: not here, not to the HTTP layer, not to the Console.
 */
final readonly class InviteExistingPerson
{
    public function __construct(
        private AuthorizeAction $authorize,
        private InviteAccountForPerson $invite,
        private DeliverInvitation $deliver,
        private AccountViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PersonNotFound the Person does not exist
     * @throws PersonHasAccount the Person already has an Account
     * @throws EmailAlreadyInUse another Account already holds this email address
     * @throws InvalidInvitationDetails the email address is not acceptable
     */
    public function __invoke(Actor $actor, PersonId $personId, string $email): OperatorInvitation
    {
        ($this->authorize)($actor, Capability::IssueInvitations);

        $issued = ($this->invite)($personId, $email, $actor);
        $delivery = ($this->deliver)($issued, $actor, 'issued');

        return new OperatorInvitation($this->views->of($issued->accountId), $delivery);
    }
}
