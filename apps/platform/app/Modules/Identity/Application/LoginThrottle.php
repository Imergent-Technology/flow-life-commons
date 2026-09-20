<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\EmailAddress;

/**
 * Login rate limiting by source address AND normalised identifier. A port so the use
 * case stays free of the framework's limiter; Infrastructure implements it.
 *
 * Implementations must treat known and unknown identifiers identically.
 */
interface LoginThrottle
{
    public function block(?string $ip, EmailAddress $email): ?ThrottleBlock;

    /** Counts an attempt from this address (successful or not). */
    public function recordAttempt(?string $ip): void;

    public function recordFailure(EmailAddress $email): void;

    public function clearFailures(EmailAddress $email): void;
}
