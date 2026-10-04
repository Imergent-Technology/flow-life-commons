<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\CardAudienceConflict;
use App\Modules\Resources\Application\CardNotPublishable;
use App\Modules\Resources\Application\ManagedPackView;
use App\Modules\Resources\Application\PackNotPublishable;
use App\Modules\Resources\Application\PublishedPackRequirement;
use App\Modules\Resources\Application\StaleRevision;
use App\Modules\Resources\Domain\InvalidResourceInput;
use Illuminate\Http\JsonResponse;

/**
 * How the Resources API's refusals look on the wire: a stable `code` the Console branches on, and nothing the caller was not
 * already entitled to know. No SQL, no constraint name, never the offending text echoed back. A DELIVERY refusal is one answer
 * for every reason (`resource_pack_not_found`): it must not say whether something was missing, a Draft, unpublished, not for this
 * viewer or empty after projection (ADR 0037, decision 46).
 */
final class ResourcesProblems
{
    public static function notFound(string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], 404);
    }

    public static function conflict(string $code, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], 409);
    }

    public static function stale(StaleRevision $e, ResourcesPresenter $presenter): JsonResponse
    {
        $current = $e->current;

        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'stale_revision',
            'current' => $current instanceof ManagedPackView ? $presenter->pack($current) : $presenter->card($current),
        ], 409);
    }

    public static function packNotPublishable(PackNotPublishable $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => 'pack_not_publishable', 'unmet' => $e->unmet], 409);
    }

    public static function cardNotPublishable(CardNotPublishable $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => 'card_not_publishable', 'unmet' => $e->unmet], 409);
    }

    public static function publishedPackRequirement(PublishedPackRequirement $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'code' => 'published_pack_requirement', 'requirement' => $e->requirement], 409);
    }

    public static function cardAudienceConflict(CardAudienceConflict $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'card_audience_conflict',
            'cards' => array_map(static fn ($id): string => $id->value, $e->cards),
        ], 409);
    }

    public static function invalidInput(InvalidResourceInput $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->problem,
            'errors' => [$e->field => [$e->getMessage()]],
        ], 422);
    }

    public static function unknownCategory(): JsonResponse
    {
        return response()->json([
            'message' => 'There is no such category to put the Pack in.',
            'code' => 'unknown_category',
            'errors' => ['category_id' => ['There is no such category.']],
        ], 422);
    }
}
