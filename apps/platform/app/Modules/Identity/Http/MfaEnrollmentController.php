<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\BeginTotpEnrollment;
use App\Modules\Identity\Application\SecondFactorFailure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A person who has proved their password and must enrol an authenticator asks for a secret. It returns the
 * secret (as a manual key) and the provisioning URI (for a QR code drawn in the browser) ONCE; nothing
 * afterwards can read them back. Nothing is enrolled by asking: only proving the secret does that.
 */
final readonly class MfaEnrollmentController
{
    public function __invoke(Request $request, BeginTotpEnrollment $begin, ConsoleSession $session): JsonResponse
    {
        $pending = $session->pending($request);
        if ($pending === null) {
            return MfaProblems::signInExpired();
        }

        $setup = $begin($pending);
        if ($setup instanceof SecondFactorFailure) {
            $session->forgetPending($request);

            return MfaProblems::signInExpired();
        }

        return response()->json([
            'secret' => $setup->secret->reveal(),
            'otpauth_uri' => $setup->provisioningUri,
            'expires_at' => $session->pendingExpiresAt($request)?->toIso8601ZuluString(),
        ])->header('Cache-Control', 'no-store');
    }
}
