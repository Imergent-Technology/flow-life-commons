<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

/**
 * The configured upload ceiling (`resources.assets.max_bytes`, ADR 0037 decision 64), read where it is enforced. The APPLICATION
 * refuses a larger file itself (`413 file_too_large`); PHP's own `upload_max_filesize` and `post_max_size` must be at least this, and
 * `security:production-check` fails when they are not, but they are never the product's validation.
 *
 * Bounded in code, 1 MiB to 100 MiB, so a mistyped setting cannot remove the limit or make every upload impossible.
 */
final class AssetLimits
{
    public const int DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    public const int FLOOR = 1024 * 1024;

    public const int CEILING = 100 * 1024 * 1024;

    public static function maxBytes(): int
    {
        return max(self::FLOOR, min(self::CEILING, config()->integer('resources.assets.max_bytes', self::DEFAULT_MAX_BYTES)));
    }

    /** "20 MB" for 20 MiB: how the limit is said to a person. */
    public static function describe(int $bytes): string
    {
        return $bytes % (1024 * 1024) === 0 ? intdiv($bytes, 1024 * 1024).' MB' : number_format($bytes / (1024 * 1024), 1).' MB';
    }
}
