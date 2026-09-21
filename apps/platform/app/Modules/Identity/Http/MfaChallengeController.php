<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\CompleteSecondFactor;
use App\Modules\Identity\Application\SecondFactorFailure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;

/**
 * Finishes a sign-in whose password was proved: an authenticator code, or a recovery code. On the session
 * surface (cookie and CSRF apply) but NOT behind authentication: what it needs is the pending sign-in.
 *
 * Only success establishes the authenticated session, and only after CompleteSecondFactor has re-read the
 * Account under a lock and committed, and only if the Account's security generation is still the one that
 * proof was checked against (ADR 0025). A wrong code is a validation error and counts toward ending the
 * pending sign-in; every other refusal (Account disabled, password replaced, wrong step, nothing pending,
 * a reset that overtook the proof) ends it and reads the same as an expired one.
 */
final readonly class MfaChallengeController
{
    public function __invoke(
        MfaChallengeRequest $request,
        CompleteSecondFactor $complete,
        ConsoleSession $session,
        CurrentAccountPresenter $presenter,
        Config $config,
    ): JsonResponse {
        $pending = $session->pending($request);
        if ($pending === null) {
            return MfaProblems::signInExpired();
        }

        $proof = $request->proof();
        $outcome = $complete($pending, $proof, new ClientContext($request->ip(), $request->userAgent()));

        if (! $outcome->succeeded() || $outcome->account === null) {
            if ($outcome->failure === SecondFactorFailure::InvalidCode) {
                $session->recordPendingFailure($request);

                return MfaProblems::invalidCode($proof->method);
            }
            $session->forgetPending($request);

            return MfaProblems::signInExpired();
        }

        if (! $session->establish($request, $outcome->account->actor->accountId, $outcome->securityGeneration ?? 0, secondFactor: true)) {
            // Disabled, or its security state superseded, between the commit and here (ADR 0025).
            $session->forgetPending($request);

            return MfaProblems::signInExpired();
        }

        return response()->json($presenter->present(
            $outcome->account,
            $session->authenticatedAt($request) ?? throw new \LogicException('Session has no authentication time.'),
            $config->integer('identity.session.absolute_lifetime_minutes'),
            $session->securityVerifiedAt($request),
            $config->integer('identity.mfa.security_verification_max_age_minutes'),
        ));
    }
}
