<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Shared\Domain\AccountId;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

/**
 * The Guardian Console session as the transport sees it: the one place that touches
 * Laravel's session guard. Application and Domain never do (they stay free of the
 * framework's authentication globals).
 *
 * The session stores `authenticated_at`, the instant of authentication. It is what the
 * absolute lifetime is measured from, and it is independent of Laravel's sliding
 * `last_activity`. Re-authenticating writes a new one.
 */
final readonly class ConsoleSession
{
    public const string AUTHENTICATED_AT = 'authenticated_at';

    public function __construct(private AuthManager $auth) {}

    /**
     * Establishes an authenticated session for an Account that has already been verified.
     * The session id and CSRF token are both regenerated, so nothing an attacker planted
     * before login (session fixation) survives it.
     */
    public function establish(Request $request, AccountId $accountId): void
    {
        // loginUsingId regenerates the session: a new id, the old one destroyed, and a new
        // CSRF token (Store::regenerate does all three). Nothing planted before login survives.
        if ($this->guard()->loginUsingId($accountId->value) === false) {
            throw new LogicException('An account that just authenticated could not be resolved.');
        }

        $request->session()->put(self::AUTHENTICATED_AT, now()->getTimestamp());
    }

    /** The Account this session was authenticated as, read from the session itself. */
    public function accountId(Request $request): ?AccountId
    {
        $stored = $request->session()->get($this->guard()->getName());
        if (! is_string($stored)) {
            return null;
        }

        try {
            return AccountId::fromString($stored);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** The raw stored value: whatever is there, so callers can fail safe on anything odd. */
    public function authenticatedAtRaw(Request $request): mixed
    {
        return $request->session()->get(self::AUTHENTICATED_AT);
    }

    public function authenticatedAt(Request $request): ?CarbonImmutable
    {
        $raw = $this->authenticatedAtRaw($request);

        return is_int($raw) ? CarbonImmutable::createFromTimestampUTC($raw) : null;
    }

    /** End the session at the user's request. */
    public function terminate(Request $request): void
    {
        $this->guard()->logout();
        $this->discard($request);
    }

    /**
     * End the session because the platform's rules say so. invalidate() flushes the CSRF
     * token along with everything else, so a fresh one is needed for the next request.
     */
    public function discard(Request $request): void
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->guard()->forgetUser();
    }

    private function guard(): SessionGuard
    {
        $guard = $this->auth->guard('web');
        assert($guard instanceof SessionGuard);

        return $guard;
    }
}
