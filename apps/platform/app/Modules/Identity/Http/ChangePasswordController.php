<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ChangePassword;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Application\ResolveActor;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Changes the signed-in account's password. On the session surface: cookie, CSRF, absolute lifetime
 * and authentication all apply before this runs (the route is in the `stateful` group with `auth:web`).
 *
 * The use case ends the account's OTHER sessions; the transport then rotates THIS one (new id, new
 * CSRF token, old row destroyed) and restarts its authentication instant, because the current password
 * was just re-proved. That happens only after the change has committed: a wrong password or a refused
 * new one leaves the session exactly as it was. Failures become responses through CredentialProblems.
 */
final readonly class ChangePasswordController
{
    public function __invoke(
        ChangePasswordRequest $request,
        ChangePassword $change,
        ResolveActor $resolveActor,
        ConsoleSession $session,
    ): Response {
        $actor = $this->actor($request, $resolveActor) ?? throw new NoLongerAuthenticated;

        $change(
            $actor, $request->currentPassword(), $request->password(),
            $request->session()->getId(), new ClientContext($request->ip(), $request->userAgent()),
        );
        $session->reauthenticate($request);

        return response()->noContent();
    }

    private function actor(Request $request, ResolveActor $resolveActor): ?Actor
    {
        $user = $request->user();
        $identifier = $user instanceof Authenticatable ? $user->getAuthIdentifier() : null;

        try {
            return is_string($identifier) ? $resolveActor(AccountId::fromString($identifier)) : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
