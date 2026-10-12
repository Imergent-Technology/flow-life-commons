<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\InvalidBasicDetail;
use App\Modules\Relationships\Application\InvalidRelationshipQuery;
use App\Modules\Relationships\Application\PersonAdvice;
use App\Modules\Relationships\Application\PossibleDuplicatePersonAdvice;
use App\Modules\Relationships\Application\StaleBasicDetails;
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

    /** The three current values, which the Actor's scope already lets them read, and nothing else (ADR 0038, P5). */
    public static function staleBasics(StaleBasicDetails $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'stale_person_basics',
            'current' => [
                'display_name' => $e->current->displayName,
                'primary_email' => $e->current->primaryEmail,
                'primary_phone' => $e->current->primaryPhone,
            ],
        ], 409);
    }

    /** CRM's advice shape: id, display name and what matched. Never a contact value (ADR 0038, P7). */
    public static function possibleDuplicate(PossibleDuplicatePersonAdvice $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => 'possible_duplicate',
            'candidates' => array_map(static fn (PersonAdvice $candidate): array => [
                'id' => $candidate->id->value,
                'display_name' => $candidate->displayName,
                'matched_on' => $candidate->matchedOn,
            ], $e->candidates),
        ], 409);
    }

    public static function invalidBasics(InvalidBasicDetail $e): JsonResponse
    {
        return self::field('invalid_person_basics', $e->field, $e->getMessage());
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
