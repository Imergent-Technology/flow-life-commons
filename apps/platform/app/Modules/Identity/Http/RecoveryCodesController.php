<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Application\RegenerateRecoveryCodes;
use Illuminate\Http\JsonResponse;

/**
 * Replaces the signed-in Account's recovery codes. Fresh proof in the body (current password AND a second
 * factor), never the session alone. Returns the new codes ONCE; the old ones stop working in the same
 * transaction. The proof also refreshes recent security verification (the session id is rotated).
 */
final readonly class RecoveryCodesController
{
    public function __invoke(SecurityProofRequest $request, RegenerateRecoveryCodes $regenerate, ConsoleActor $actors, ConsoleSession $session): JsonResponse
    {
        $actor = $actors->for($request) ?? throw new NoLongerAuthenticated;

        $codes = $regenerate($actor, $request->currentPassword(), $request->proof(), new ClientContext($request->ip(), $request->userAgent()));
        $session->markSecurityVerified($request);

        return response()->json(['recovery_codes' => $codes])->header('Cache-Control', 'no-store');
    }
}
