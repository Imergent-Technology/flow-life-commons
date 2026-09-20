<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\EmailAddress;

/**
 * Delivers a password-reset token to the Account's owner: Identity's own credential-recovery message,
 * and nothing more general (there is no notifications module). A port, so the use case never touches
 * the mail system.
 *
 * It is called after the transaction that stored the token has committed, and it MUST NOT throw: the
 * public response is the same whether or not delivery worked, so a failure is the adapter's to record
 * (without the token), not the caller's to react to.
 */
interface PasswordResetNotifier
{
    public function send(EmailAddress $to, IssuedPasswordReset $reset): void;
}
