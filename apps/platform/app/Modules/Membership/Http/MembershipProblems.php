<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Membership\Domain\InvalidMembershipTerm;
use Illuminate\Http\JsonResponse;

/**
 * How the Membership administration API's refusals look on the wire, the same shape
 * `Access\Http\AdministrationProblems` established: a stable machine-readable `code`, and nothing
 * the caller was not already entitled to know. Owned separately here because a module's Http may
 * not depend on another module's Http (ModuleBoundariesTest).
 */
final class MembershipProblems
{
    public static function personNotFound(): JsonResponse
    {
        return response()->json(['message' => 'There is no such Person.', 'code' => 'person_not_found'], 404);
    }

    public static function recordNotFound(): JsonResponse
    {
        return response()->json(['message' => 'That Person has no membership record.', 'code' => 'membership_record_not_found'], 404);
    }

    public static function grantNotFound(): JsonResponse
    {
        return response()->json(['message' => 'There is no such membership grant.', 'code' => 'grant_not_found'], 404);
    }

    public static function grantAlreadyRevoked(): JsonResponse
    {
        return response()->json(['message' => 'This membership grant has already been revoked.', 'code' => 'grant_already_revoked'], 409);
    }

    public static function invalidMembershipTerm(InvalidMembershipTerm $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'invalid_membership_term',
            'errors' => ['ends_at' => [$e->getMessage()]],
        ], 422);
    }
}
