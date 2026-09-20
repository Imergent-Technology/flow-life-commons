<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use RuntimeException;

/**
 * An authenticated caller's second-factor proof was refused (a management operation or step-up), and
 * nothing was changed. Thrown from inside a transaction before anything is written, so the failure event
 * is recorded by whoever catches it, outside that transaction, where a rollback cannot take it away.
 */
final class SecondFactorRejected extends RuntimeException
{
    /** @param  SecondFactorMethod|null  $method  what the caller presented, so the answer can name the right field */
    public function __construct(
        public readonly SecondFactorFailure $reason,
        public readonly ?Account $account = null,
        public readonly ?SecondFactorMethod $method = null,
    ) {
        parent::__construct('The second factor was not accepted.');
    }
}
