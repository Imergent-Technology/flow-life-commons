<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Mfa;

use App\Modules\Identity\Application\MultiFactorPolicy;
use App\Shared\Domain\Actor;

/**
 * The policy Identity uses when no other module has registered one: FAIL CLOSED. Access registers the
 * real one (a person whose access reaches the privileged Console must have a second factor), and it wins
 * because its provider is loaded after Identity's. If that registration were ever lost, the safe outcome
 * is that everyone is asked for a second factor, never that nobody is.
 */
final readonly class AlwaysRequireMultiFactor implements MultiFactorPolicy
{
    public function requiredFor(Actor $actor): bool
    {
        return true;
    }
}
