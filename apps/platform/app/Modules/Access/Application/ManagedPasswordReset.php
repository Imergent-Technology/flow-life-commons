<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\PasswordResetDelivery;

/**
 * What an operator's "send a password reset email" came to: the Account as it stands and whether the message went. It
 * NEVER carries the reset token or link: those reach nobody but the Account's own address.
 */
final readonly class ManagedPasswordReset
{
    public function __construct(
        public AccountView $account,
        public PasswordResetDelivery $delivery,
    ) {}
}
