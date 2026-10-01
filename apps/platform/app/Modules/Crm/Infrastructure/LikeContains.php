<?php

declare(strict_types=1);

namespace App\Modules\Crm\Infrastructure;

/**
 * A "contains" pattern for `lower(col) like ? escape '!'`, portable in strategy across MariaDB and PostgreSQL, with
 * `%`, `_` and the escape character made literal. The same technique as Identity's own (a module may not use another's
 * Infrastructure, so it is restated here). ASCII and case behave the same on both engines; accent sensitivity follows
 * each engine's collation and may differ.
 */
final class LikeContains
{
    private const string ESCAPE = '!';

    public static function pattern(string $fragment): string
    {
        return '%'.str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            mb_strtolower($fragment),
        ).'%';
    }

    /**
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function predicate(string $column): string
    {
        return 'lower('.$column.') like ? escape \''.self::ESCAPE.'\'';
    }
}
