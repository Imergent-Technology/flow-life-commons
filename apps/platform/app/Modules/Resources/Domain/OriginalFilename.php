<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use Normalizer;

/**
 * The name a file was uploaded under, made safe to keep and to show (ADR 0037, decision 63). It is DISPLAY METADATA ONLY: it never
 * becomes a path or a storage key (those come from the asset's id), and it is the name a download is offered under.
 *
 * What the client sent is taken as untrusted text and reduced, predictably, to something that cannot be read as a path or a header:
 *
 * - invalid UTF-8 is replaced, not guessed at; the result is Unicode NFC, so one name has one form;
 * - any directory part is dropped, whichever separator it used (`../../etc/passwd` and `C:\Users\x\report.pdf` keep only the last part);
 * - runs of whitespace (tab, CR and LF included) become one space; then every other control character (NUL, say), the line and
 *   paragraph separators, and the bidirectional embedding, override and isolate characters are removed: the last are how
 *   `invoice\u{202E}fdp.exe` displays as `invoiceexe.pdf`. Joiners and direction marks stay, as ordinary text in many scripts and
 *   in emoji. No CR or LF can survive, so the name can never break a header;
 * - leading and trailing spaces, and trailing dots, are trimmed;
 * - it is at most 255 characters, cut in the base name so the extension survives.
 *
 * Nothing else is changed: a name with quotes, accents, emoji or a second dot is kept as written, because the header that carries it
 * is encoded where it is built (RFC 6266), not by mangling the name here. The extension is what the type check compares with the
 * content, lower-cased; a name with none has an empty extension, which no kind accepts.
 */
final readonly class OriginalFilename
{
    public const int MAX = 255;

    private function __construct(public string $value, public string $extension) {}

    public static function fromClient(string $raw): self
    {
        $name = mb_check_encoding($raw, 'UTF-8') ? $raw : mb_scrub($raw, 'UTF-8');
        $normalized = class_exists(Normalizer::class) ? Normalizer::normalize($name, Normalizer::FORM_C) : $name;
        $name = is_string($normalized) ? $normalized : $name;

        // The last path segment, whatever the separator.
        $segments = preg_split('#[/\\\\]#u', $name);
        $name = is_array($segments) ? (string) end($segments) : '';

        // Whitespace (tab, CR, LF and the rest) first becomes one space, so it still separates words; then what is left of the
        // control characters, the line and paragraph separators and the bidirectional controls is removed.
        $name = (string) preg_replace('/[\s\p{Zs}]+/u', ' ', $name);
        $name = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name);
        $name = (string) preg_replace('/ {2,}/', ' ', $name);
        $name = rtrim(trim($name, ' '), ' .');

        $extension = '';
        $dot = mb_strrpos($name, '.');
        if ($dot !== false && $dot < mb_strlen($name) - 1) {
            $candidate = mb_strtolower(mb_substr($name, $dot + 1));
            if (preg_match('/\A[a-z0-9]{1,16}\z/', $candidate) === 1) {
                $extension = $candidate;
            }
        }

        if (mb_strlen($name) > self::MAX) {
            $suffix = $extension === '' ? '' : '.'.mb_substr($name, -mb_strlen($extension));
            $name = rtrim(mb_substr($name, 0, self::MAX - mb_strlen($suffix)), ' .').$suffix;
        }

        return new self($name, $extension);
    }

    public function isEmpty(): bool
    {
        return $this->value === '';
    }
}
