<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Shared\Domain\Actor;

/**
 * Sends a committed invitation to its address, and makes a failure visible instead of hiding it.
 *
 * **Delivery is after the commit and outside it.** SMTP is not part of the database transaction, and rolling back
 * an Account that was correctly created because a mail server hiccuped would be the wrong repair. So the state is
 * committed first; this runs afterwards; and if the message could not be sent the caller is TOLD (`Failed`), an
 * `invitation.delivery_failed` event is recorded, and the operator issues a fresh invitation (ReissueInvitation).
 * The unsent token is not recoverable and not stored, so there is nothing to resend: a new one is the only remedy.
 *
 * Only the fact of failure is recorded, never the token or the link.
 */
final readonly class DeliverInvitation
{
    public function __construct(
        private InvitationNotifier $notifier,
        private RecordSecurityEvent $record,
    ) {}

    /** @param  string  $action  `issued` or `reissued`: which operation the invitation came from */
    public function __invoke(IssuedInvitation $invitation, ?Actor $by, string $action): InvitationDelivery
    {
        $outcome = $this->notifier->send($invitation);

        if ($outcome === InvitationDelivery::Failed) {
            ($this->record)(
                IdentityEvent::InvitationDeliveryFailed->value, SecurityEventOutcome::Failure,
                $by, $invitation->personId, $invitation->accountId, null, null,
                ['action' => $action],
            );
        }

        return $outcome;
    }
}
