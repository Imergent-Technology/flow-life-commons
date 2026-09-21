<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\InvitationDelivery;

/**
 * What issuing (or reissuing) an invitation came to: the Account as it now stands, and whether the message went. It
 * NEVER carries the invitation secret: that reaches nobody but the address it was mailed to.
 */
final readonly class OperatorInvitation
{
    public function __construct(
        public AccountView $account,
        public InvitationDelivery $delivery,
    ) {}
}
