<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\AuthenticateAccount;
use App\Modules\Identity\Application\AuthenticationStatus;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvalidEmailAddress;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use LogicException;

final readonly class LoginController
{
    public function __invoke(
        LoginRequest $request,
        AuthenticateAccount $authenticate,
        ConsoleSession $session,
        CurrentAccountPresenter $presenter,
        Config $config,
    ): JsonResponse {
        try {
            $email = EmailAddress::fromString($request->email());
        } catch (InvalidEmailAddress) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
        }

        $result = $authenticate($email, $request->password(), new ClientContext($request->ip(), $request->userAgent()));

        if ($result->status === AuthenticationStatus::Throttled) {
            return response()->json(['message' => 'Too many sign-in attempts. Try again later.'], 429)
                ->header('Retry-After', (string) $result->retryAfterSeconds);
        }

        if ($result->status === AuthenticationStatus::SecondFactorPending && $result->pending !== null) {
            // The password was right, but this Account needs a second factor: NOT signed in. The pending
            // sign-in is all that is held (no guard login), and the answer says what comes next, which the
            // caller has earned by proving the password. It is `202`, not `200`: nothing is established.
            $session->holdPending($request, $result->pending);

            return response()->json([
                'next' => $result->pending->need->value,
                'expires_at' => $session->pendingExpiresAt($request)?->toIso8601ZuluString(),
            ], 202);
        }

        if ($result->status === AuthenticationStatus::Failed || $result->account === null) {
            // One response for unknown address, wrong password, invited and disabled alike.
            return response()->json(['message' => 'The provided credentials are incorrect.'], 401);
        }

        if (! $session->establish($request, $result->account->actor->accountId)) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 401);
        }
        $authenticatedAt = $session->authenticatedAt($request) ?? throw new LogicException('Session has no authentication time.');

        return response()->json($presenter->present(
            $result->account,
            $authenticatedAt,
            $config->integer('identity.session.absolute_lifetime_minutes'),
            $session->securityVerifiedAt($request),
            $config->integer('identity.mfa.security_verification_max_age_minutes'),
        ));
    }
}
