<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The step-up seam (`security.verified`, ADR 0023): a route behind it needs the caller to have proved
 * password AND a second factor recently, on THIS session (default: within 15 minutes).
 *
 * Being signed in is not enough, and neither is having signed in with a second factor a while ago: what
 * counts is the last verification. It answers `403` with `verification_required: true`, so a client knows
 * to prompt (POST /security/verify) and retry. It FAILS SAFE: a missing, malformed or future-dated
 * instant is "not verified".
 *
 * It must run after authentication (a request with no session is a 401, not a 403). Nothing uses it yet:
 * it exists for the sensitive administration that follows. Apply it as `->middleware('security.verified')`
 * after `auth:web`; do not hand-roll a check.
 */
final readonly class RequireRecentSecurityVerification
{
    /** Tolerated clock skew between nodes, in seconds, before a future instant is distrusted. */
    private const int SKEW_SECONDS = 60;

    public function __construct(private ConsoleSession $session, private Config $config) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $verifiedAt = $this->session->securityVerifiedAt($request)?->getTimestamp();
        $now = now()->getTimestamp();
        $maxAge = $this->config->integer('identity.mfa.security_verification_max_age_minutes') * 60;

        if ($verifiedAt === null || $verifiedAt > $now + self::SKEW_SECONDS || $now >= $verifiedAt + $maxAge) {
            return MfaProblems::verificationRequired();
        }

        return $next($request);
    }
}
