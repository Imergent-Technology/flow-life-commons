<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\PossibleDuplicate;
use App\Modules\Crm\Domain\InvalidContactInput;
use Illuminate\Http\JsonResponse;

/**
 * How the People API's refusals look on the wire: a stable `code` the Console branches on, and nothing the caller was
 * not already entitled to know. No SQL, no constraint name, and never the offending value echoed back.
 */
final class CrmProblems
{
    public static function personNotFound(): JsonResponse
    {
        return response()->json(['message' => 'There is no such Person.', 'code' => 'person_not_found'], 404);
    }

    public static function contactMethodNotFound(): JsonResponse
    {
        return response()->json(['message' => 'There is no such contact method for that Person.', 'code' => 'contact_method_not_found'], 404);
    }

    public static function tagNotFound(): JsonResponse
    {
        return response()->json(['message' => 'There is no such tag.', 'code' => 'tag_not_found'], 404);
    }

    public static function duplicateContactMethod(): JsonResponse
    {
        return response()->json(['message' => 'That Person already has that contact method.', 'code' => 'duplicate_contact_method'], 409);
    }

    public static function duplicateTag(): JsonResponse
    {
        return response()->json(['message' => 'A tag with that name already exists.', 'code' => 'duplicate_tag'], 409);
    }

    public static function tagInUse(): JsonResponse
    {
        return response()->json(['message' => 'That tag is on at least one Person, so it cannot be deleted.', 'code' => 'tag_in_use'], 409);
    }

    public static function unknownTags(): JsonResponse
    {
        return response()->json([
            'message' => 'One or more of those tags do not exist.',
            'code' => 'unknown_tag',
            'errors' => ['tag_ids' => ['One or more of those tags do not exist.']],
        ], 422);
    }

    public static function searchTooBroad(): JsonResponse
    {
        return response()->json([
            'message' => 'That matches too many People to use as a search. Be more specific.',
            'code' => 'search_too_broad',
            'errors' => ['q' => ['That matches too many People to use as a search. Be more specific.']],
        ], 422);
    }

    public static function invalidInput(InvalidContactInput $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'invalid_contact_input',
            'errors' => [$e->field => [$e->getMessage()]],
        ], 422);
    }

    public static function possibleDuplicate(PossibleDuplicate $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'possible_duplicate',
            'candidates' => array_map(static fn ($candidate): array => [
                'id' => $candidate->person->id->value,
                'display_name' => $candidate->person->displayName,
                'matched_on' => $candidate->matchedOn,
            ], $e->candidates),
        ], 409);
    }
}
