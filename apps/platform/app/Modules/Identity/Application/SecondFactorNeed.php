<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** What signing in with a correct password must be followed by. */
enum SecondFactorNeed: string
{
    /** Nothing: a password is enough for this Account. */
    case None = 'none';

    /** The Account has an authenticator and must prove a code from it (or a recovery code). */
    case Challenge = 'challenge';

    /** A second factor is required and the Account has none yet: it must enrol one first. */
    case Enrollment = 'enrollment';
}
