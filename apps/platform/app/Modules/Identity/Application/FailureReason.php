<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * Why an authentication attempt failed: a reason CLASS for the audit trail, never the
 * submitted secret. The caller of the API never learns which one it was.
 */
enum FailureReason: string
{
    case UnknownAccount = 'unknown_account';
    case WrongPassword = 'wrong_password';
    case AccountNotActive = 'account_not_active';
}
