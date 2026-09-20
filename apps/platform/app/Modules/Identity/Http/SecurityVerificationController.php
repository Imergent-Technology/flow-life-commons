<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Application\VerifySecurityAccess;
use Illuminate\Http\Response;

/**
 * Step-up. The signed-in person re-proves the current password AND a second factor, and the session
 * records "recently verified" (the id is rotated). A route behind `security.verified` then accepts them for
 * the next few minutes. It changes nothing else: not the session's authentication time, not the Account.
 */
final readonly class SecurityVerificationController
{
    public function __invoke(SecurityProofRequest $request, VerifySecurityAccess $verify, ConsoleActor $actors, ConsoleSession $session): Response
    {
        $actor = $actors->for($request) ?? throw new NoLongerAuthenticated;

        $verify($actor, $request->currentPassword(), $request->proof(), new ClientContext($request->ip(), $request->userAgent()));
        $session->markSecurityVerified($request);

        return response()->noContent();
    }
}
