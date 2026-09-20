<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * Delivers an invitation to the address it was issued for: Identity's own message, and nothing more general
 * (there is no notifications module). A port, so the use cases never touch the mail system.
 *
 * It is called only after the transaction that created the invitation has committed, and it is the ONLY place
 * the raw token leaves the process (the administrator bootstrap's console output is the other, for an operator).
 * It reports failure rather than throwing, and never logs or returns the token or the link.
 */
interface InvitationNotifier
{
    public function send(IssuedInvitation $invitation): InvitationDelivery;
}
