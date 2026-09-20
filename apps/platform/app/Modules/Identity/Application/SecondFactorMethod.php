<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** How a second factor was satisfied. Values are recorded in the audit trail. */
enum SecondFactorMethod: string
{
    case Totp = 'totp';
    case RecoveryCode = 'recovery_code';

    /** Proving a freshly generated secret at enrolment satisfies the factor for that sign-in. */
    case Enrollment = 'enrollment';
}
