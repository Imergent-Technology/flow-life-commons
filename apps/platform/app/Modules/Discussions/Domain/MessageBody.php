<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Domain;

/**
 * The rules for the text of a message (ADR 0035): line endings normalised to LF, trimmed, required, at most 10,000
 * characters, no control character other than LF and TAB. Owned here rather than shared with CRM's notes, which follow a
 * similar policy: a module may not use another's Domain, and the two are free to diverge.
 */
final class MessageBody
{
    public const int MAX_LENGTH = 10000;

    /** @throws InvalidDiscussionInput */
    public static function normalise(string $body): string
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body)); // CRLF, then a lone CR, become LF
        if ($body === '') {
            throw new InvalidDiscussionInput('body', 'Write something to post.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new InvalidDiscussionInput('body', 'A message is limited to '.self::MAX_LENGTH.' characters.');
        }
        // Control characters only (Cc), other than LF and TAB. Formatting characters (Cf) are ordinary text.
        if (preg_match('/[^\P{Cc}\n\t]/u', $body) === 1) {
            throw new InvalidDiscussionInput('body', 'A message may not contain control characters.');
        }

        return $body;
    }
}
