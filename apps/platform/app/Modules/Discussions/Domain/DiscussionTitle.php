<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Domain;

/** The rules for a discussion's title (ADR 0035): trimmed, one line, required, at most 200 characters. */
final class DiscussionTitle
{
    public const int MAX_LENGTH = 200;

    /** @throws InvalidDiscussionInput */
    public static function normalise(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidDiscussionInput('title', 'Give the discussion a title.');
        }
        if (mb_strlen($title) > self::MAX_LENGTH) {
            throw new InvalidDiscussionInput('title', 'A title is limited to '.self::MAX_LENGTH.' characters.');
        }
        // One line: no control character (Unicode Cc, which includes tab and newline) and no line or paragraph separator.
        // Formatting characters (Cf: ZWNJ, ZWJ, direction marks) are ordinary text in many languages and emoji sequences.
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $title) === 1) {
            throw new InvalidDiscussionInput('title', 'A title is a single line and may not contain control characters.');
        }

        return $title;
    }
}
