<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain\Content;

/**
 * Derives a Card's automatic summary from its validated content (ADR 0037, decision 35). Deterministic: the same document and
 * length always give the same text.
 *
 * The document's plain text is cut at `$length` characters. If the cut falls inside a word, it backs off to the last space when
 * that space lies in the second half of the allowance (so a long unbroken word is cut hard rather than leaving almost nothing),
 * and an ellipsis marks that text was dropped. Text that fits is returned whole.
 */
final class SummaryDeriver
{
    /** The shortest and longest allowance a setting may ask for: a summary column holds 300 characters. */
    public const int MIN_LENGTH = 20;

    public const int MAX_LENGTH = 299;

    public const string ELLIPSIS = '…';

    public static function derive(ContentDocument $content, int $length): string
    {
        $length = max(self::MIN_LENGTH, min(self::MAX_LENGTH, $length));
        $text = $content->plainText();
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        // The cut already ends on a word when the next character is a space: nothing to back off from.
        if (mb_substr($text, $length, 1) !== ' ') {
            $space = mb_strrpos($cut, ' ');
            if ($space !== false && $space >= intdiv($length, 2)) {
                $cut = mb_substr($cut, 0, $space);
            }
        }

        return rtrim($cut).self::ELLIPSIS;
    }
}
