<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Auth;

use Illuminate\Contracts\Auth\CanResetPassword;
use LogicException;

/**
 * What Laravel's token repository needs to know about an Account: the identifier its tokens are keyed
 * by. That is the Account's CANONICAL email, so the same address in any case is the same token row on
 * both engines (ADR 0015). Deliberately not the Account, the Eloquent record or a domain object: it
 * is the framework's view, built at the edge and thrown away.
 */
final readonly class ResetSubject implements CanResetPassword
{
    public function __construct(private string $canonicalEmail) {}

    public function getEmailForPasswordReset(): string
    {
        return $this->canonicalEmail;
    }

    public function sendPasswordResetNotification($token): never
    {
        // Delivery goes through Identity's own PasswordResetNotifier, never the framework's notification.
        throw new LogicException('Password reset messages are sent by PasswordResetNotifier.');
    }
}
