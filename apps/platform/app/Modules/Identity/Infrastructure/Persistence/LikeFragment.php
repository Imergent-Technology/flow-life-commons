<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

/**
 * A user's text as a case-insensitive "contains" pattern, portable in strategy across MariaDB and PostgreSQL: the
 * caller lower()s the column, and the pattern is lower-cased here with `%`, `_` and the escape character itself made
 * literal, used with `ESCAPE '!'`.
 *
 * What "the same" means: case-insensitive ASCII matching is identical on both engines. Accent sensitivity is NOT
 * folded here and follows each engine's collation: on MariaDB (`utf8mb4_unicode_ci`) `José` matches `jose`, on
 * PostgreSQL (`en_US.utf8`) it does not. Ordering of non-ASCII text follows the same collations. Accent folding would
 * be a separate decision; nothing here attempts it.
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
