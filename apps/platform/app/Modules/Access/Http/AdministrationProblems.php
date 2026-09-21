<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Identity\Application\InvalidInvitationDetails;
use Illuminate\Http\JsonResponse;

/**
 * How the administration API's refusals look on the wire. Application throws its own exceptions (it may not use
 * HTTP); bootstrap/app.php renders them through here. Every one carries a stable machine-readable `code`, so the
 * Console tells them apart without parsing a sentence, and none leaks anything the caller was not entitled to know:
 * the caller is an authorized, freshly verified operator, and these describe the state of what they asked to change.
 */
final class AdministrationProblems
{
    public static function notFound(): JsonResponse
    {
        return response()->json(['message' => 'There is no such account.', 'code' => 'account_not_found'], 404);
    }

    public static function conflict(string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], 409);
    }

    /** A refusal that is about the request, not about state: nothing was changed. `field` ties it to the input it concerns. */
    public static function invalid(string $code, string $field, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code, 'errors' => [$field => [$message]]], 422);
    }

    public static function invalidDetails(InvalidInvitationDetails $e): JsonResponse
    {
        return self::invalid('invalid_invitation_details', $e->field, $e->getMessage());
    }
}
