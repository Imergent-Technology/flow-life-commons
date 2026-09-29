<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

/**
 * A user's text as a case-insensitive "contains" pattern, portable across MariaDB and PostgreSQL: the caller lower()s
 * the column, and the pattern is lower-cased here with `%`, `_` and the escape character itself made literal, used
 * with `ESCAPE '!'`. (Collation is not relied on: the engines collate a plain VARCHAR differently.)
 */
final class LikeFragment
{
    public const string ESCAPE = '!';

    /** The pattern for a non-empty, already-trimmed fragment. */
    public static function contains(string $fragment): string
    {
        return '%'.str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            mb_strtolower($fragment),
        ).'%';
    }

    /**
     * The SQL predicate for a lower-cased column: `lower(col) like ? escape '!'`. The column is a name written in
     * code, never user input, which is why it is typed as a literal.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public static function predicate(string $column): string
    {
        return 'lower('.$column.') like ? escape \''.self::ESCAPE.'\'';
    }
}
