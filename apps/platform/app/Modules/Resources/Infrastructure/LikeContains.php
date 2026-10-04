<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure;

/**
 * A "contains" pattern for `lower(col) like ? escape '!'`, portable in strategy across MariaDB and PostgreSQL, with `%`, `_` and
 * the escape character made literal. Used by MANAGEMENT filtering only: it discloses nothing a manager may not see. The consumer
 * library never uses SQL for text (decision 48): it searches the projection in PHP, so hidden Cards cannot match. Restated
 * here, as Crm and Discussions restate it, because a module may not use another's Infrastructure.
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
