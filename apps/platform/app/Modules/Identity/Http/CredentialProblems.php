<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\PasswordRejected;
use App\Modules\Identity\Application\TooManyAttempts;
use App\Modules\Identity\Domain\PasswordViolation;
use Illuminate\Http\JsonResponse;

/**
 * How the credential endpoints' failures look on the wire. Application throws its own exceptions
 * (it may not use HTTP); bootstrap/app.php renders them through here, so every endpoint that sets a
 * password answers the same way.
 *
 * Nothing here carries the password, the token or anything derived from them.
 */
final class CredentialProblems
{
    /** How long a client is told to wait before retrying when the breach check is unavailable. */
    private const int RETRY_AFTER_SECONDS = 30;

    public static function passwordRejected(PasswordRejected $e): JsonResponse
    {
        return response()->json([
            'message' => 'The password does not meet the requirements.',
            'errors' => ['password' => array_map(self::describe(...), $e->violations)],
        ], 422);
    }

    public static function checkUnavailable(): JsonResponse
    {
        return response()->json(
            ['message' => 'The password could not be checked right now. Nothing was changed; try again shortly.'],
            503,
        )->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }

    public static function invitationRejected(): JsonResponse
    {
        // One answer for unknown, malformed, expired, used and revoked alike.
        return response()->json([
            'message' => 'The invitation is invalid or has expired.',
            'errors' => ['token' => ['The invitation is invalid or has expired.']],
        ], 422);
    }

    public static function currentPasswordIncorrect(): JsonResponse
    {
        return response()->json([
            'message' => 'The current password is incorrect.',
            'errors' => ['current_password' => ['The current password is incorrect.']],
        ], 422);
    }

    public static function resetRejected(): JsonResponse
    {
        // One answer for an unknown address, an Account that cannot be reset, and a missing, wrong,
        // used or expired token alike.
        return response()->json([
            'message' => 'The password reset link is invalid or has expired.',
            'errors' => ['token' => ['The password reset link is invalid or has expired.']],
        ], 422);
    }

    public static function tooManyAttempts(TooManyAttempts $e): JsonResponse
    {
        return response()->json(['message' => 'Too many attempts. Try again later.'], 429)
            ->header('Retry-After', (string) $e->retryAfterSeconds);
    }

    private static function describe(PasswordViolation $violation): string
    {
        return match ($violation) {
            PasswordViolation::TooShort => 'Use at least 15 characters. A few unrelated words make a strong passphrase.',
            PasswordViolation::TooLong => 'Use at most 72 bytes: about 72 ordinary characters, fewer if you use accented or non-Latin ones.',
            PasswordViolation::NotUtf8 => 'The password must be valid UTF-8 text.',
            PasswordViolation::ContainsNul => 'The password must not contain a null character.',
            PasswordViolation::Compromised => 'This password appears in known data breaches or is too common. Choose a different one.',
        };
    }
}
