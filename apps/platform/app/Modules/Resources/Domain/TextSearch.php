<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * The pure "contains" match both reading policies share and nothing else (ADR 0038, R5, R8): a case-insensitive, Unicode-folded
 * substring test over texts the CALLER chose. It decides nothing about which texts may be offered: the privileged viewer offers every
 * Card's title and summary, audience delivery offers only the ones the projection let through, and that choice is each policy's own.
 * Done in PHP so the two database engines cannot disagree, and rich content is never searched.
 */
final class TextSearch
{
    /** The search text as it is matched: trimmed, and null when nothing is left to look for. */
    public static function needle(?string $text): ?string
    {
        $needle = $text === null ? '' : trim($text);

        return $needle === '' ? null : $needle;
    }

    /** @param  list<string>  $texts */
    public static function matches(string $needle, array $texts): bool
    {
        foreach ($texts as $text) {
            if ($text !== '' && mb_stripos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
