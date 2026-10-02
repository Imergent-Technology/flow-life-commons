<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Domain\InvalidDiscussionInput;
use Illuminate\Http\JsonResponse;

/**
 * How the Discussions API's refusals look on the wire: a stable `code` the Console branches on, and nothing the caller was
 * not already entitled to know. No SQL, no constraint name, never the offending text echoed back, and never the text of a
 * removed message.
 */
final class DiscussionsProblems
{
    public static function discussionNotFound(): JsonResponse
    {
        return response()->json(['message' => 'There is no such discussion.', 'code' => 'discussion_not_found'], 404);
    }

    public static function messageNotFound(): JsonResponse
    {
        return response()->json(['message' => 'There is no such message in that discussion.', 'code' => 'message_not_found'], 404);
    }

    public static function notAuthor(): JsonResponse
    {
        return response()->json(['message' => 'Only the person who wrote that can change it.', 'code' => 'not_author'], 403);
    }

    public static function discussionResolved(): JsonResponse
    {
        return response()->json(['message' => 'That discussion is resolved. Reopen it to reply.', 'code' => 'discussion_resolved'], 409);
    }

    public static function messageRemoved(): JsonResponse
    {
        return response()->json(['message' => 'That message has been removed.', 'code' => 'message_removed'], 409);
    }

    public static function invalidInput(InvalidDiscussionInput $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'invalid_discussion_input',
            'errors' => [$e->field => [$e->getMessage()]],
        ], 422);
    }
}
