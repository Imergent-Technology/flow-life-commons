<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure;

use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceSet;
use App\Modules\Resources\Domain\Provenance;
use App\Shared\Domain\PersonId;
use stdClass;

/**
 * Reading what the database returned, once, for every repository: strings that are strings, numbers that are numbers (an
 * engine may hand back either for an integer column), and provenance that is whole.
 */
final class Rows
{
    public static function string(stdClass $row, string $column): string
    {
        $value = $row->{$column} ?? null;
        assert(is_string($value), "{$column} is a string");

        return $value;
    }

    public static function nullableString(stdClass $row, string $column): ?string
    {
        $value = $row->{$column} ?? null;
        assert($value === null || is_string($value), "{$column} is a string or null");

        return $value;
    }

    public static function int(stdClass $row, string $column): int
    {
        $value = $row->{$column} ?? null;
        assert(is_int($value) || is_string($value), "{$column} is a number");

        return (int) $value;
    }

    public static function bool(stdClass $row, string $column): bool
    {
        $value = $row->{$column} ?? null;
        assert(is_bool($value) || is_int($value) || is_string($value), "{$column} is a flag");

        return (bool) $value;
    }

    public static function provenance(stdClass $row): Provenance
    {
        return new Provenance(
            PersonId::fromString(self::string($row, 'created_by_person_id')),
            SqlTime::from(self::string($row, 'created_at')),
            PersonId::fromString(self::string($row, 'updated_by_person_id')),
            SqlTime::from(self::string($row, 'updated_at')),
        );
    }

    /**
     * A set from stored audience keys. A key the catalog no longer knows is dropped: it matches no viewer (fail closed).
     *
     * @param  list<string>  $keys
     */
    public static function audiences(array $keys): AudienceSet
    {
        $audiences = [];
        foreach ($keys as $key) {
            $audience = Audience::tryFrom($key);
            if ($audience !== null) {
                $audiences[] = $audience;
            }
        }

        return AudienceSet::fromList($audiences);
    }
}
