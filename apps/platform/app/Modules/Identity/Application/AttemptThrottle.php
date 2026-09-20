<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * Rate limiting for the credential endpoints, by source address and (where there is one) by
 * identifier. A port, so the use cases stay free of the framework's limiter; login keeps its own,
 * because its failure-counting semantics differ. Every attempt counts, whatever its outcome.
 *
 * Each action has separate counters: hammering one endpoint for an identifier must not lock that
 * identifier out of another. Implementations must treat a known and an unknown identifier
 * identically, so a limit never reveals whether an Account exists.
 */
interface AttemptThrottle
{
    public function block(ThrottledAction $action, ?string $ip, ?string $identifier): ?ThrottleBlock;

    public function record(ThrottledAction $action, ?string $ip, ?string $identifier): void;
}
