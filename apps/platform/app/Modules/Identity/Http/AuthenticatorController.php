<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\BeginAuthenticatorReplacement;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Starts replacing the authenticator. Fresh proof in the body (current password AND a second factor). It
 * returns a NEW secret, provisioning URI and expiry, once, as PENDING: the authenticator in use keeps working until
 * the new one is proved (AuthenticatorConfirmController), so an abandoned replacement strands no one.
 */
final readonly class AuthenticatorController
{
    public function __invoke(SecurityProofRequest $request, BeginAuthenticatorReplacement $begin, ConsoleActor $actors, ConsoleSession $session): JsonResponse
    {
        $actor = $actors->for($request) ?? throw new NoLongerAuthenticated;

        $setup = $begin($actor, $request->currentPassword(), $request->proof(), new ClientContext($request->ip(), $request->userAgent()));
        $session->markSecurityVerified($request);

        return response()->json([
            'secret' => $setup->secret->reveal(),
            'otpauth_uri' => $setup->provisioningUri,
            // Until when the pending secret can be confirmed. The server owns this clock; the Console only shows it.
            'expires_at' => CarbonImmutable::createFromInterface($setup->expiresAt)->toIso8601ZuluString(),
        ])->header('Cache-Control', 'no-store');
    }
}
