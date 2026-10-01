<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\EmailAddress;

/**
 * Delivers a password-reset token to the Account's owner: Identity's own credential-recovery message,
 * and nothing more general (there is no notifications module). A port, so the use case never touches
 * the mail system.
 *
 * It is called after the transaction that stored the token has committed, and it MUST NOT throw: a failure is
 * the adapter's to log (without the token) and to REPORT as `Failed`. The public "I forgot my password" answer is the
 * same whether or not delivery worked, so it ignores the result; an operator who asked for the message is told.
 */
interface PasswordResetNotifier
{
    public function send(EmailAddress $to, IssuedPasswordReset $reset): PasswordResetDelivery;
}
