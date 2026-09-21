<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Narrows the untyped things a test reads (decoded JSON, route metadata) to the shapes it expects, failing loudly
 * when they are not. It keeps assertions about a response readable without an `assert(is_array(...))` on every line.
 */
final class Api
{
    public static function string(mixed $value): string
    {
        assert(is_string($value), 'expected text, got '.get_debug_type($value));

        return $value;
    }

    /** @return list<string> */
    public static function strings(mixed $value): array
    {
        assert(is_array($value));

        return array_values(array_map(self::string(...), $value));
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        assert(is_array($value));

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return list<array<string, mixed>> */
    public static function rows(mixed $value): array
    {
        assert(is_array($value));

        return array_values(array_map(self::map(...), $value));
    }
}
