<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Domain\RelationshipId;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Reads mutation identity from a request without deciding the relationship's state. */
final class RelationshipInput
{
    /**
     * Field values from the raw JSON body. The framework turns an empty string into null, and an empty
     * string is not a clear: null is.
     */
    public static function fields(Request $request): mixed
    {
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload) || ! array_key_exists('fields', $payload)) {
            return [];
        }

        return $payload['fields'];
    }

    public static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /** A missing or malformed id cannot match a stored instance, so the use case answers stale or not found. */
    public static function instance(mixed $value): RelationshipId
    {
        try {
            return is_string($value) ? RelationshipId::fromString($value) : RelationshipId::generate();
        } catch (InvalidArgumentException) {
            return RelationshipId::generate();
        }
    }
}
