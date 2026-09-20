<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Auth;

use App\Modules\Identity\Application\LoginThrottle;
use App\Modules\Identity\Application\ThrottleBlock;
use App\Modules\Identity\Domain\EmailAddress;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Login throttling on Laravel's limiter and the configured cache store (the database
 * store; no Redis). See config/identity.php for the policy and its trade-offs.
 *
 * Keys are hashed: the identifier counter is keyed by an HMAC of the canonical email, so
 * a cache table dump does not list the addresses that were tried, and a known and an
 * unknown identifier are indistinguishable.
 */
final readonly class CacheLoginThrottle implements LoginThrottle
{
    public function __construct(
        private RateLimiter $limiter,
        private Config $config,
    ) {}

    public function block(?string $ip, EmailAddress $email): ?ThrottleBlock
    {
        $ipKey = $this->ipKey($ip);
        if ($this->limiter->tooManyAttempts($ipKey, $this->int('max_attempts_per_ip'))) {
            return $this->blocked('ip', $ipKey);
        }

        $identifierKey = $this->identifierKey($email);
        if ($this->limiter->tooManyAttempts($identifierKey, $this->int('max_failures_per_identifier'))) {
            return $this->blocked('identifier', $identifierKey);
        }

        return null;
    }

    public function recordAttempt(?string $ip): void
    {
        $this->limiter->hit($this->ipKey($ip), $this->int('decay_seconds'));
    }

    public function recordFailure(EmailAddress $email): void
    {
        $this->limiter->hit($this->identifierKey($email), $this->int('decay_seconds'));
    }

    public function clearFailures(EmailAddress $email): void
    {
        $this->limiter->clear($this->identifierKey($email));
    }

    private function blocked(string $scope, string $key): ThrottleBlock
    {
        // attempt() succeeds at most once per interval, which is exactly "audit it once".
        $auditable = $this->limiter->attempt('login-audit:'.$key, 1, static fn (): bool => true, $this->int('audit_interval_seconds'));

        return new ThrottleBlock($scope, max(1, $this->limiter->availableIn($key)), $auditable !== false);
    }

    private function ipKey(?string $ip): string
    {
        return 'login:ip:'.hash('sha256', $ip ?? 'unknown');
    }

    private function identifierKey(EmailAddress $email): string
    {
        return 'login:id:'.hash_hmac('sha256', $email->canonical, $this->config->string('app.key'));
    }

    private function int(string $key): int
    {
        return $this->config->integer("identity.login_throttle.{$key}");
    }
}
