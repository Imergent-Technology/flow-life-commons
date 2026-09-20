<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\PasswordViolation;
use App\Modules\Identity\Domain\PlainPassword;

/**
 * THE password policy: one, used by every path that sets a password (invitation acceptance,
 * password reset, authenticated change). Nothing else decides what an acceptable password is.
 *
 * - Minimum 15 Unicode code points; at most 72 UTF-8 bytes (bcrypt's limit, refused rather than
 *   truncated). No composition rules; spaces and Unicode are fine. Those are PlainPassword's.
 * - Not known from public breaches (the port). No expiry schedule, no history, no hints.
 *
 * The breach check is a network call, so it happens HERE, before the caller opens its database
 * transaction, and only for a password that already passed the rules that need no network: a
 * password that is plainly too short is never sent anywhere, not even as a hash prefix.
 */
final readonly class PasswordPolicy
{
    public function __construct(private CompromisedPasswords $compromised) {}

    /**
     * @throws PasswordRejected the password does not meet the policy
     * @throws CompromisedPasswordCheckUnavailable the breach check could not be completed
     */
    public function assertAcceptable(PlainPassword $password): void
    {
        $violations = $password->violations();
        if ($violations !== []) {
            throw new PasswordRejected($violations);
        }

        if ($this->compromised->contains($password)) {
            throw new PasswordRejected([PasswordViolation::Compromised]);
        }
    }
}
