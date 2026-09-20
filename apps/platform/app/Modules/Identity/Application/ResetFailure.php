<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** Why a reset request or completion did not proceed: a reason CLASS for the audit trail, never shown to the caller. */
enum ResetFailure: string
{
    case UnknownAccount = 'unknown_account';
    case AccountNotEligible = 'account_not_eligible';
    case RecentlyRequested = 'recently_requested';
    case InvalidToken = 'invalid_token';
}
