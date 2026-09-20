<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\ExpireSession;
use App\Modules\Identity\Application\ExpiryReason;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 12-hour absolute cap on an authenticated session (ADR 0016).
 *
 * Laravel's own session lifetime slides: every request refreshes `last_activity`, so a
 * cookie used every few minutes would live forever. This measures from `authenticated_at`
 * instead, which requests never touch:
 *
 *     expired  <=>  now >= authenticated_at + absolute lifetime
 *
 * It FAILS SAFE. An authenticated session with no usable `authenticated_at` (missing, not
 * an integer, or in the future) is treated as expired, never as unlimited. Expiry ends
 * the session and records `session.absolute_expired`; the request then continues as an
 * anonymous one, so protected routes answer 401.
 *
 * It must run before the authentication middleware; see bootstrap/app.php.
 */
final readonly class EnforceAbsoluteSessionLifetime
{
    /** Tolerated clock skew between nodes, in seconds, before a future timestamp is distrusted. */
    private const int SKEW_SECONDS = 60;

    public function __construct(
        private ConsoleSession $session,
        private ExpireSession $expire,
        private Config $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $this->session->accountId($request);
        if ($accountId === null) {
            return $next($request); // not an authenticated session: nothing to bound
        }

        $reason = $this->expiryReason($this->session->authenticatedAtRaw($request));
        if ($reason !== null) {
            $lifetime = $this->lifetimeMinutes();
            $this->session->discard($request);
            ($this->expire)($accountId, $reason, $lifetime, new ClientContext($request->ip(), $request->userAgent()));
        }

        return $next($request);
    }

    private function expiryReason(mixed $authenticatedAt): ?ExpiryReason
    {
        if ($authenticatedAt === null) {
            return ExpiryReason::MissingAuthenticatedAt;
        }

        $now = now()->getTimestamp();
        if (! is_int($authenticatedAt) || $authenticatedAt > $now + self::SKEW_SECONDS) {
            return ExpiryReason::InvalidAuthenticatedAt;
        }

        return $now >= $authenticatedAt + $this->lifetimeMinutes() * 60 ? ExpiryReason::AbsoluteLifetime : null;
    }

    private function lifetimeMinutes(): int
    {
        return $this->config->integer('identity.session.absolute_lifetime_minutes');
    }
}
