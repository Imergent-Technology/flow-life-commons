<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\ConfirmAuthenticatorReplacement;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use Illuminate\Http\Response;

/**
 * Proves the NEW authenticator; only now does it replace the old one. The Account's other sessions end and
 * this one is rotated (a credential was replaced) after the change has committed.
 */
final readonly class AuthenticatorConfirmController
{
    public function __invoke(MfaCodeRequest $request, ConfirmAuthenticatorReplacement $confirm, ConsoleActor $actors, ConsoleSession $session): Response
    {
        $actor = $actors->for($request) ?? throw new NoLongerAuthenticated;

        $securityGeneration = $confirm($actor, $request->proof(), $request->session()->getId(), new ClientContext($request->ip(), $request->userAgent()));
        // The replacement advanced the Account's security generation, which ended every OTHER session; this
        // one is re-bound to the generation that transaction committed (ADR 0025).
        $session->rebind($request, $securityGeneration);
        $session->markSecurityVerified($request);

        return response()->noContent();
    }
}
