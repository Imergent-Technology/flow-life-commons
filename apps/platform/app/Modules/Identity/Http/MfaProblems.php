<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\SecondFactorFailure;
use App\Modules\Identity\Application\SecondFactorMethod;
use App\Modules\Identity\Application\SecondFactorRejected;
use Illuminate\Http\JsonResponse;

/**
 * How second-factor failures look on the wire. One sentence for a wrong code whatever it was and however
 * it was wrong; and one for "start again", which is all anyone unauthenticated is ever told about a
 * half-finished sign-in that cannot continue (expired, ended, or invalidated), so the answer gives nothing
 * away about the Account. Nothing here carries a code, a secret or anything derived from them.
 */
final class MfaProblems
{
    public static function invalidCode(?SecondFactorMethod $method): JsonResponse
    {
        $field = $method === SecondFactorMethod::RecoveryCode ? 'recovery_code' : 'code';

        return response()->json([
            'message' => 'The code is not valid.',
            'errors' => [$field => ['The code is not valid.']],
        ], 422);
    }

    /** A half-finished sign-in that cannot be continued. Identical for every reason. */
    public static function signInExpired(): JsonResponse
    {
        return response()->json(['message' => 'This sign-in has expired. Sign in again.'], 401);
    }

    public static function rejected(SecondFactorRejected $e): JsonResponse
    {
        if ($e->reason === SecondFactorFailure::InvalidCode) {
            return self::invalidCode($e->method);
        }

        // No authenticator setup in progress (or none to replace): say so, on the field it concerns.
        return response()->json([
            'message' => 'There is no authenticator setup in progress. Start again.',
            'errors' => ['authenticator' => ['There is no authenticator setup in progress. Start again.']],
        ], 422);
    }

    /** Recent security verification is needed: the Console prompts for it and retries. */
    public static function verificationRequired(): JsonResponse
    {
        return response()->json([
            'message' => 'Recent security verification is required.',
            'verification_required' => true,
        ], 403);
    }
}
