<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Auth;

use App\Modules\Identity\Application\AttemptThrottle;
use App\Modules\Identity\Application\ThrottleBlock;
use App\Modules\Identity\Application\ThrottledAction;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Credential-endpoint throttling on Laravel's limiter and the configured cache store (the database
 * store; no Redis), in the same style as CacheLoginThrottle: keys are hashed, the identifier counter
 * is keyed by an HMAC so a cache table dump does not list the identifiers tried, and a known and an
 * unknown identifier are indistinguishable. See config/identity.php for the limits.
 *
 * Every attempt is counted, successful or not, against the source address and, when the endpoint has
 * one, against the identifier. Each action has its own counters.
 */
final readonly class CacheAttemptThrottle implements AttemptThrottle
{
    public function __construct(
        private RateLimiter $limiter,
        private Config $config,
    ) {}

    public function block(ThrottledAction $action, ?string $ip, ?string $identifier): ?ThrottleBlock
    {
        $ipKey = $this->ipKey($action, $ip);
        if ($this->limiter->tooManyAttempts($ipKey, $this->limit($action, 'per_ip') ?? PHP_INT_MAX)) {
            return $this->blocked($ipKey, 'ip');
        }

        $identifierLimit = $this->limit($action, 'per_identifier');
        if ($identifier !== null && $identifierLimit !== null) {
            $identifierKey = $this->identifierKey($action, $identifier);
            if ($this->limiter->tooManyAttempts($identifierKey, $identifierLimit)) {
                return $this->blocked($identifierKey, 'identifier');
            }
        }

        return null;
    }

    public function record(ThrottledAction $action, ?string $ip, ?string $identifier): void
    {
        $decay = $this->config->integer('identity.credential_throttle.decay_seconds');

        $this->limiter->hit($this->ipKey($action, $ip), $decay);
        if ($identifier !== null && $this->limit($action, 'per_identifier') !== null) {
            $this->limiter->hit($this->identifierKey($action, $identifier), $decay);
        }
    }

    private function blocked(string $key, string $scope): ThrottleBlock
    {
        // attempt() succeeds at most once per interval, which is exactly "audit it once".
        $auditable = $this->limiter->attempt(
            'credential-audit:'.$key, 1, static fn (): bool => true,
            $this->config->integer('identity.credential_throttle.audit_interval_seconds'),
        );

        return new ThrottleBlock($scope, max(1, $this->limiter->availableIn($key)), $auditable !== false);
    }

    private function limit(ThrottledAction $action, string $which): ?int
    {
        $value = $this->config->get("identity.credential_throttle.{$action->value}.{$which}");

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function ipKey(ThrottledAction $action, ?string $ip): string
    {
        return "credential:{$action->value}:ip:".hash('sha256', $ip ?? 'unknown');
    }

    private function identifierKey(ThrottledAction $action, string $identifier): string
    {
        return "credential:{$action->value}:id:".hash_hmac('sha256', $identifier, $this->config->string('app.key'));
    }
}
