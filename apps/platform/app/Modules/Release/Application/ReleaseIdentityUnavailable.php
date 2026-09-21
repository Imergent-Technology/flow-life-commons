<?php

declare(strict_types=1);

namespace App\Modules\Release\Application;

use RuntimeException;

/**
 * This deployment cannot say which release it is.
 *
 * Always an error, never an "unknown": a deployed release that cannot identify itself is one nobody
 * can verify, roll back with confidence, or match to a backup. The distinction the message carries is
 * WHY — a source working tree has no manifest and never had one, which is ordinary; a release
 * directory without one is damaged.
 */
final class ReleaseIdentityUnavailable extends RuntimeException
{
    private function __construct(string $message, public readonly bool $isSourceTree = false)
    {
        parent::__construct($message);
    }

    public static function noManifest(string $path): self
    {
        return new self("No release manifest at $path.", isSourceTree: true);
    }

    public static function unreadable(string $path): self
    {
        return new self("The release manifest at $path exists but could not be read.");
    }

    public static function malformed(string $path, string $why): self
    {
        return new self("The release manifest at $path is not valid: $why");
    }

    public static function incomplete(string $field, string $why): self
    {
        return new self("The release manifest is missing or has an invalid '$field': $why");
    }
}
