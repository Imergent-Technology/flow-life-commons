<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\ConfirmTotpEnrollment;
use App\Modules\Identity\Application\SecondFactorFailure;
use Illuminate\Http\JsonResponse;

/**
 * The person proves they hold the secret they were shown. Only a valid current code makes the authenticator
 * real, completes the sign-in (the session is established here, with a second factor), and returns the
 * recovery codes, ONCE. A wrong code changes nothing and the pending secret stays for another try.
 */
final readonly class MfaEnrollmentConfirmController
{
    public function __invoke(MfaCodeRequest $request, ConfirmTotpEnrollment $confirm, ConsoleSession $session): JsonResponse
    {
        $pending = $session->pending($request);
        if ($pending === null) {
            return MfaProblems::signInExpired();
        }

        $outcome = $confirm($pending, $request->proof(), new ClientContext($request->ip(), $request->userAgent()));

        if (! $outcome->succeeded() || $outcome->account === null) {
            if ($outcome->failure === SecondFactorFailure::InvalidCode) {
                $session->recordPendingFailure($request);

                return MfaProblems::invalidCode(null);
            }
            $session->forgetPending($request);

            return MfaProblems::signInExpired();
        }

        if (! $session->establish($request, $outcome->account->actor->accountId, secondFactor: true)) {
            return MfaProblems::signInExpired();
        }

        // Shown once. Not cacheable, and stored nowhere on the server: only their digests are.
        return response()->json(['recovery_codes' => $outcome->recoveryCodes])->header('Cache-Control', 'no-store');
    }
}
