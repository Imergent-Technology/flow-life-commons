<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * The one rule for an external address in Resources (ADR 0037, decision 24), owned here because Resources is its only
 * consumer. It decides whether a string is safe to STORE and to hand a browser as a link; the platform never fetches it, so
 * there is no server-side request surface to defend.
 *
 * Accepted: `https` or `http` with a host, an optional port, and any path, query or fragment, at most 2,048 characters, with no
 * user-information part, whitespace, control character, backslash, angle bracket or double quote. A link INSIDE rich content
 * may also be `mailto:` with one plain address. Refused: `javascript:`, `data:`, `vbscript:`, `file:`, every other scheme, and
 * every relative reference. The scheme is stored lower-cased; nothing else is rewritten.
 */
final class ExternalUri
{
    public const int MAX_LENGTH = 2048;

    private const string LABEL = '[\p{L}\p{N}](?:[\p{L}\p{N}-]*[\p{L}\p{N}])?';

    /** The normalised address, or null when it is not acceptable. */
    public static function normalise(string $raw, bool $allowMailto = false): ?string
    {
        // Only ordinary whitespace is trimmed: PHP's default `trim` also strips NUL, which would quietly clean up a control character.
        $uri = trim($raw, " \t\n\r\v\f");
        if ($uri === '' || ! mb_check_encoding($uri, 'UTF-8') || mb_strlen($uri) > self::MAX_LENGTH) {
            return null;
        }
        if (preg_match('/[\p{Cc}\s\\\\<>"]/u', $uri) !== 0) {
            return null;
        }

        if ($allowMailto && preg_match('/^mailto:([^@?#%\s]+@'.self::LABEL.'(?:\.'.self::LABEL.')+)$/iu', $uri, $mail) === 1) {
            return 'mailto:'.$mail[1];
        }

        if (preg_match('~^(https?)://([^/?#]*)([/?#].*)?$~isu', $uri, $parts) !== 1) {
            return null;
        }
        $authority = $parts[2];
        if ($authority === '' || str_contains($authority, '@')) {
            return null;
        }
        if (! self::validAuthority($authority)) {
            return null;
        }

        return strtolower($parts[1]).'://'.$authority.($parts[3] ?? '');
    }

    /** @throws InvalidResourceInput */
    public static function fromInput(string $raw): string
    {
        return self::normalise($raw) ?? throw new InvalidResourceInput('uri', 'Enter a web address that starts with https:// or http://.', InvalidResourceInput::URI);
    }

    private static function validAuthority(string $authority): bool
    {
        if (str_starts_with($authority, '[')) {
            return preg_match('/^\[[0-9A-Fa-f:.]+\](?::(\d{1,5}))?$/', $authority, $m) === 1 && self::validPort($m[1] ?? null);
        }
        if (preg_match('/^([^:]+)(?::(\d{1,5}))?$/u', $authority, $m) !== 1) {
            return false;
        }

        return preg_match('/^'.self::LABEL.'(?:\.'.self::LABEL.')*\.?$/u', $m[1]) === 1 && self::validPort($m[2] ?? null);
    }

    private static function validPort(?string $port): bool
    {
        return $port === null || $port === '' || ((int) $port >= 1 && (int) $port <= 65535);
    }
}
