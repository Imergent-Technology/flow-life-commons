<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\EndSupersededSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The enforcement point of the security-generation invariant (ADR 0025).
 *
 * A session carries the generation its authentication proof was checked against. This compares it with
 * the Account's current one on every request and ends the session when they differ, BEFORE the
 * authentication middleware runs, so the request then continues as an anonymous one and protected
 * routes answer 401.
 *
 * It is what makes the invariant provable rather than likely. `ConsoleSession::establish` already
 * refuses to create a session whose generation has been superseded, so in practice the row is usually
 * never written; but the transport persists the session after the response is prepared, so a reset
 * committing in that last instant still leaves a row behind. That row is dead on arrival here. The same
 * check also covers a subtler case that deleting rows cannot: a request already in flight when a reset
 * deletes its row writes that row back when it finishes.
 *
 * It runs last of the session checks, immediately before authentication; see bootstrap/app.php.
 */
final readonly class EnforceSecurityGeneration
{
    public function __construct(
        private ConsoleSession $session,
        private EndSupersededSession $superseded,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $this->session->accountId($request);
        if ($accountId === null) {
            return $next($request); // not an authenticated session: nothing is bound
        }

        if (($this->superseded)($accountId, $this->session->securityGenerationRaw($request), new ClientContext($request->ip(), $request->userAgent()))) {
            $this->session->discard($request);
        }

        return $next($request);
    }
}
