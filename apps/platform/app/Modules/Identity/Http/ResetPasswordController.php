<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvalidEmailAddress;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Completes a password reset. Public and stateless. It does not sign the caller in, and it ends every
 * session the Account has. Failures become responses through CredentialProblems: one answer for every
 * reason the reset cannot proceed.
 */
final readonly class ResetPasswordController
{
    public function __invoke(ResetPasswordRequest $request, ResetPassword $reset): Response
    {
        try {
            $email = EmailAddress::fromString($request->email());
        } catch (InvalidEmailAddress) {
            throw ValidationException::withMessages(['email' => 'Enter a valid email address.']);
        }

        $reset($email, $request->token(), $request->password(), new ClientContext($request->ip(), $request->userAgent()));

        return response()->noContent();
    }
}
