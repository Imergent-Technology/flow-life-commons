<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ChangePassword;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use Illuminate\Http\Response;

/**
 * Changes the signed-in account's password. On the session surface: cookie, CSRF, absolute lifetime
 * and authentication all apply before this runs (the route is in the `stateful` group with `auth:web`).
 *
 * The use case ends the account's OTHER sessions; the transport then rotates THIS one (new id, new
 * CSRF token, old row destroyed). That happens only after the change has committed: a wrong password,
 * a missing or wrong second factor or a refused new password leaves the session exactly as it was.
 *
 * Rotating is ALL it does to the session. A password change is not a sign-in: `authenticated_at` (and so
 * the 12-hour absolute expiry) and the recent-security-verification instant are carried over untouched.
 * Failures become responses through CredentialProblems and MfaProblems.
 */
final readonly class ChangePasswordController
{
    public function __invoke(
        ChangePasswordRequest $request,
        ChangePassword $change,
        ConsoleActor $actors,
        ConsoleSession $session,
    ): Response {
        $actor = $actors->for($request) ?? throw new NoLongerAuthenticated;

        $securityGeneration = $change(
            $actor, $request->currentPassword(), $request->password(),
            $request->session()->getId(), new ClientContext($request->ip(), $request->userAgent()),
            $request->proof(),
        );
        // The change advanced the Account's security generation, which ended every OTHER session. This one
        // is re-bound to the generation that transaction committed (ADR 0025), then rotated.
        $session->rebind($request, $securityGeneration);
        $session->rotate($request);

        return response()->noContent();
    }
}
