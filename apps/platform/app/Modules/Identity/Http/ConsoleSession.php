<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\AccountSecurityGeneration;
use App\Modules\Identity\Application\PendingLogin;
use App\Modules\Identity\Application\SecondFactorNeed;
use App\Shared\Domain\AccountId;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The Guardian Console session as the transport sees it: the one place that touches
 * Laravel's session guard. Application and Domain never do (they stay free of the
 * framework's authentication globals).
 *
 * The session stores `authenticated_at`, the instant of authentication. It is what the
 * absolute lifetime is measured from, and it is independent of Laravel's sliding
 * `last_activity`. Re-authenticating writes a new one.
 *
 * Multi-factor authentication (ADR 0023) adds two more, and one state that is NOT authentication:
 *
 * - `second_factor_verified_at`: present only if the session was established with a second factor.
 * - `security_verified_at`: the last time the person proved password AND a second factor, which is what
 *   `security.verified` measures.
 * - a PENDING sign-in: the password was proved and the second factor is awaited. It is a small record in
 *   the session (Account, the digest that binds it to the password proved, what is due, when it began,
 *   how many codes were wrong). It is NOT a guard login: `/me` is unauthenticated, no Gate is satisfied,
 *   and it lives for minutes, measured from the password and never extended by activity.
 *
 * And one more, which is what makes the session's authority revocable (ADR 0025):
 *
 * - `security_generation`: the Account's security generation as it stood when the proof behind this
 *   session was checked, under the Account's row lock. EnforceSecurityGeneration compares it with the
 *   Account's current one on every request.
 */
final readonly class ConsoleSession
{
    public const string AUTHENTICATED_AT = 'authenticated_at';

    public const string SECURITY_GENERATION = 'security_generation';

    public const string SECOND_FACTOR_VERIFIED_AT = 'second_factor_verified_at';

    public const string SECURITY_VERIFIED_AT = 'security_verified_at';

    public const string PENDING = 'pending_sign_in';

    public function __construct(
        private AuthManager $auth,
        private Config $config,
        private AccountSecurityGeneration $generations,
    ) {}

    /**
     * Establishes an authenticated session for an Account that has already been verified.
     * The session id and CSRF token are both regenerated, so nothing an attacker planted
     * before login (session fixation) survives it.
     *
     * `$securityGeneration` is the Account's security generation as the use case read it under the
     * Account's row lock (ADR 0025). It is checked against committed state FIRST, so a reset, disable
     * or credential replacement that committed between that proof and this call means no session is
     * created at all rather than one that has to be cleaned up. The generation is then stored on the
     * session, which is what makes the remaining instant — between here and the transport writing the
     * row — harmless: EnforceSecurityGeneration refuses the row on its first use.
     */
    public function establish(Request $request, AccountId $accountId, int $securityGeneration, bool $secondFactor = false): bool
    {
        if ($this->generations->current($accountId) !== $securityGeneration) {
            // Superseded between the proof committing and here: nothing is established for it.
            return false;
        }

        // loginUsingId regenerates the session: a new id, the old one destroyed, and a new
        // CSRF token (Store::regenerate does all three). Nothing planted before login survives.
        if ($this->guard()->loginUsingId($accountId->value) === false) {
            // Disabled between authenticating and here: no session is established for it.
            return false;
        }

        $request->session()->forget(self::PENDING);
        $request->session()->put(self::AUTHENTICATED_AT, now()->getTimestamp());
        $request->session()->put(self::SECURITY_GENERATION, $securityGeneration);
        if ($secondFactor) {
            // Password AND a second factor were just proved, so this is also a fresh security verification.
            $request->session()->put(self::SECOND_FACTOR_VERIFIED_AT, now()->getTimestamp());
            $request->session()->put(self::SECURITY_VERIFIED_AT, now()->getTimestamp());
        }

        return true;
    }

    /** Whether this session was established with a second factor. */
    public function secondFactorVerified(Request $request): bool
    {
        return is_int($request->session()->get(self::SECOND_FACTOR_VERIFIED_AT));
    }

    /** When password and a second factor were last proved on this session, if ever. */
    public function securityVerifiedAt(Request $request): ?CarbonImmutable
    {
        $raw = $request->session()->get(self::SECURITY_VERIFIED_AT);

        return is_int($raw) ? CarbonImmutable::createFromTimestampUTC($raw) : null;
    }

    /**
     * Records that password and a second factor were just proved by the signed-in person. The session id
     * and CSRF token are rotated (a privilege has been raised, so nothing from before is trusted with it,
     * and the old id stops working). `authenticated_at` is NOT touched: this proves recent verification,
     * it does not restart the absolute lifetime.
     */
    public function markSecurityVerified(Request $request): void
    {
        $request->session()->regenerate(true);
        $request->session()->put(self::SECURITY_VERIFIED_AT, now()->getTimestamp());
    }

    /**
     * Starts a half-finished sign-in. Anything this browser session held is ended first (a fresh id, no
     * guard login, no earlier state), so the pending sign-in never sits beside another Account's session.
     */
    public function holdPending(Request $request, PendingLogin $pending): void
    {
        $this->discard($request);
        $request->session()->put(self::PENDING, [
            'account' => $pending->accountId->value,
            'marker' => $pending->credentialMarker,
            'need' => $pending->need->value,
            'started' => now()->getTimestamp(),
            'failures' => 0,
        ]);
    }

    /**
     * The half-finished sign-in, if there is one and it is still within its lifetime. An expired or
     * malformed one is removed and reads as none. Its lifetime runs from the password and is never extended.
     */
    public function pending(Request $request): ?PendingLogin
    {
        $held = $request->session()->get(self::PENDING);
        if (! is_array($held)) {
            return null;
        }

        $need = is_string($held['need'] ?? null) ? SecondFactorNeed::tryFrom($held['need']) : null;
        $started = $held['started'] ?? null;
        $account = $held['account'] ?? null;
        $marker = $held['marker'] ?? null;
        if (($need !== SecondFactorNeed::Challenge && $need !== SecondFactorNeed::Enrollment) || ! is_int($started) || ! is_string($account) || ! is_string($marker)) {
            $this->forgetPending($request);

            return null;
        }
        $now = now()->getTimestamp();
        if ($started > $now + 60 || $now >= $started + $this->pendingLifetime($need)) {
            $this->forgetPending($request);

            return null;
        }

        try {
            return new PendingLogin(AccountId::fromString($account), $marker, $need);
        } catch (InvalidArgumentException) {
            $this->forgetPending($request);

            return null;
        }
    }

    /** When the pending sign-in ends, if there is one. */
    public function pendingExpiresAt(Request $request): ?CarbonImmutable
    {
        $pending = $this->pending($request);
        $held = $request->session()->get(self::PENDING);
        $started = is_array($held) ? ($held['started'] ?? null) : null;

        return $pending !== null && is_int($started)
            ? CarbonImmutable::createFromTimestampUTC($started + $this->pendingLifetime($pending->need))
            : null;
    }

    /** A wrong code. Too many end the pending sign-in: the password must be proved again. */
    public function recordPendingFailure(Request $request): void
    {
        $held = $request->session()->get(self::PENDING);
        if (! is_array($held)) {
            return;
        }
        $failures = (is_int($held['failures'] ?? null) ? $held['failures'] : 0) + 1;
        if ($failures >= $this->config->integer('identity.mfa.max_challenge_failures')) {
            $this->forgetPending($request);

            return;
        }
        $request->session()->put(self::PENDING, [...$held, 'failures' => $failures]);
    }

    public function forgetPending(Request $request): void
    {
        $request->session()->forget(self::PENDING);
    }

    private function pendingLifetime(SecondFactorNeed $need): int
    {
        return $this->config->integer($need === SecondFactorNeed::Enrollment
            ? 'identity.mfa.enrollment_lifetime_seconds'
            : 'identity.mfa.challenge_lifetime_seconds');
    }

    /**
     * The Account has just re-proved its credential (it changed its password with the current one), so
     * the same session gets a NEW identity and a NEW authentication instant.
     *
     * - `regenerate(true)`: a new session id and CSRF token, and the OLD row deleted. Without the
     *   `true` the framework keeps the old row, so the id a thief may have copied would stay valid.
     * - `authenticated_at` is restarted: re-proving the credential is a fresh authentication, so the
     *   12-hour absolute lifetime (ADR 0016: "re-authentication starts a new one") runs from now.
     */
    public function reauthenticate(Request $request): void
    {
        $request->session()->regenerate(true);
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

    /** The security generation this session is bound to, raw, so the check can fail safe on anything odd. */
    public function securityGenerationRaw(Request $request): mixed
    {
        return $request->session()->get(self::SECURITY_GENERATION);
    }

    /**
     * Re-binds THIS session to a security generation the platform has just committed for the Account
     * (ADR 0025). Only the two operations that deliberately keep the acting session while advancing the
     * generation call it — an authenticated password change, and confirming a replacement authenticator
     * — and only with the value their own transaction committed. Binding to anything else (a value read
     * afterwards, say) would let this session survive a reset that landed in between.
     */
    public function rebind(Request $request, int $securityGeneration): void
    {
        $request->session()->put(self::SECURITY_GENERATION, $securityGeneration);
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
