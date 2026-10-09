<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\InvalidRelationshipQuery;
use App\Modules\Relationships\Application\StaleRelationshipRevision;
use App\Modules\Relationships\Domain\InvalidRelationshipField;
use App\Modules\Relationships\Domain\UnknownRelationshipField;
use Illuminate\Http\JsonResponse;

/**
 * How the Relationships API's refusals look on the wire: a stable `code`, and nothing the caller was not
 * already entitled to know. No SQL, no constraint name, and never a field value echoed back.
 */
final readonly class RelationshipsProblems
{
    public function __construct(private RelationshipsPresenter $presenter) {}

    public static function notFound(): JsonResponse
    {
        return response()->json(['message' => 'There is no such relationship.', 'code' => 'relationship_not_found'], 404);
    }

    public function stale(StaleRelationshipRevision $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'stale_revision',
            'current' => $this->presenter->management($e->current),
        ], 409);
    }

    public static function exists(): JsonResponse
    {
        return response()->json(['message' => 'This Person already has this relationship.', 'code' => 'relationship_exists'], 409);
    }

    public static function inUse(): JsonResponse
    {
        return response()->json(['message' => 'This relationship is still in use.', 'code' => 'relationship_in_use'], 409);
    }

    public static function coded(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }

    public static function field(string $code, string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => [$field => [$message]],
        ], 422);
    }

    public static function unknownField(UnknownRelationshipField $e): JsonResponse
    {
        return self::field('unknown_relationship_field', $e->field, $e->getMessage());
    }

    public static function invalidField(InvalidRelationshipField $e): JsonResponse
    {
        return self::field('invalid_relationship_field', $e->field, $e->getMessage());
    }

    public static function query(InvalidRelationshipQuery $e): JsonResponse
    {
        return self::field('invalid_relationship_query', $e->field, $e->getMessage());
    }
}
