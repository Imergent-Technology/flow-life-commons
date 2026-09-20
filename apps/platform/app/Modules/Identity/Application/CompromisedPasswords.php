<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\PlainPassword;

/**
 * Whether a password is known from public breaches (or a common-password list). A port: the
 * Application layer never makes a network call, and how the answer is obtained is replaceable.
 * Today's adapter asks a k-anonymity range service; a local blocklist could replace it without
 * touching a use case.
 *
 * An implementation MUST NOT answer "no" when it could not find out. Not knowing is not safe, and
 * turning "could not check" into "clean" would let an outage quietly disable the policy. It throws
 * CompromisedPasswordCheckUnavailable instead, which callers surface as a retryable failure.
 *
 * An implementation that reaches another machine must send nothing from which the password can be
 * recovered (a short prefix of a hash, never the password or its full hash).
 */
interface CompromisedPasswords
{
    /**
     * @throws CompromisedPasswordCheckUnavailable the answer could not be established
     */
    public function contains(PlainPassword $password): bool;
}
