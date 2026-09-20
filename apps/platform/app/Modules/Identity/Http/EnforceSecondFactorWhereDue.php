<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\EndSessionWithoutSecondFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A session that was established WITHOUT a second factor must not quietly become a privileged one.
 *
 * A password-only session is right for an Account whose access does not need more. If that Account is
 * later given access that does (or enrols an authenticator elsewhere), this ends the session on its next
 * request, and the request continues anonymous (so protected routes answer 401). The person signs in again
 * and goes through enrolment or the challenge. A session that DID prove a second factor is not asked
 * anything and costs no query.
 *
 * It runs after the absolute-lifetime check and before authentication; see bootstrap/app.php.
 */
final readonly class EnforceSecondFactorWhereDue
{
    public function __construct(
        private ConsoleSession $session,
        private EndSessionWithoutSecondFactor $ends,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $this->session->accountId($request);
        if ($accountId !== null && ! $this->session->secondFactorVerified($request)
            && ($this->ends)($accountId, new ClientContext($request->ip(), $request->userAgent()))) {
            $this->session->discard($request);
        }

        return $next($request);
    }
}
