<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * Why a second-factor step was refused: a reason CLASS for the audit trail, never the submitted code.
 * Only InvalidCode is told to the caller as such; every other reason ends the half-finished sign-in and
 * reads to the caller as "start again".
 */
enum SecondFactorFailure: string
{
    case InvalidCode = 'invalid_code';
    case AccountNotActive = 'account_not_active';
    case CredentialChanged = 'credential_changed';
    case WrongStep = 'wrong_step';
    case NoPendingSecret = 'no_pending_secret';
    case AlreadyEnrolled = 'already_enrolled';
    case NotEnrolled = 'not_enrolled';
}
