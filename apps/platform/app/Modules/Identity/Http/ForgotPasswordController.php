<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\RequestPasswordReset;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvalidEmailAddress;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * "I forgot my password". Public and stateless (no session, cookie or CSRF).
 *
 * **The answer never depends on the address.** An active Account, an unknown address, an invited
 * Account and a disabled one all get the same status and body, and the same minimum duration: an
 * address with an account does more work (a lock, a hash, an email) than one without, so every
 * request is held to `identity.password_reset.response_floor_ms` before it answers. A well-formed but
 * unknown address is therefore indistinguishable from a real one. (A malformed address is refused
 * with a validation error, as at login: that reveals the address's shape, not whether it has an
 * account.) A rate limit answers `429` identically for known and unknown addresses.
 */
final readonly class ForgotPasswordController
{
    public function __invoke(ForgotPasswordRequest $request, RequestPasswordReset $requestReset, Config $config): JsonResponse
    {
        try {
            $email = EmailAddress::fromString($request->email());
        } catch (InvalidEmailAddress) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
        }

        $floor = max(0, $config->integer('identity.password_reset.response_floor_ms')) * 1000;
        (new Timebox)->call(
            fn () => $requestReset($email, new ClientContext($request->ip(), $request->userAgent())),
            $floor,
        );

        return response()->json([
            'message' => 'If an account exists for that address and can be recovered, an email with instructions is on its way.',
        ], 202);
    }
}
