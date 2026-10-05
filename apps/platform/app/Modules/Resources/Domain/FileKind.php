<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * The Phase 1 file allowlist (ADR 0037, decision 64): PDF, PNG, JPEG, WebP, GIF, plain text, CSV, DOCX, XLSX and PPTX, and
 * nothing else. A POSITIVE list: a type is accepted because it is named here, never because it is not refused somewhere.
 *
 * A file is accepted as a kind only when BOTH of these say so, and they agree:
 *
 * - its filename's extension (after the name is sanitised), case-insensitively: one of `extensions()`; and
 * - the media type the server DETECTS from its content (`fileinfo`): one of `detectedTypes()`.
 *
 * The client's `Content-Type` is never consulted: it is whatever the browser guessed, or whatever an attacker typed. What is
 * stored and served is `mediaType()`, the kind's own canonical type, so a response never carries a type the allowlist does not name.
 *
 * `detectedTypes()` is MEASURED, not assumed: it is what PHP's bundled libmagic (5.43, PHP 8.3.33) reports for each kind in
 * development, pinned by the tests. Text and CSV accept each other's detection because libmagic's CSV recognition depends on the
 * delimiter (a comma-separated file is `text/csv`, a semicolon-separated one `text/plain`); either way the content is plain text and
 * is served as the kind its extension names. Never on the list, whatever the extension says: SVG, HTML, XML, JavaScript or any
 * other script, executables and archives. libmagic reports HTML for a `<script>` tag anywhere near the start of a "text" file, so
 * such a file is refused as HTML; a text file whose script libmagic does not recognise is still only ever served as `text/plain`,
 * as an attachment, with `nosniff`.
 *
 * Only PDF and the raster images may be offered `inline`, on request (decision 66); everything else is always an attachment.
 */
enum FileKind: string
{
    case Pdf = 'pdf';
    case Png = 'png';
    case Jpeg = 'jpeg';
    case Webp = 'webp';
    case Gif = 'gif';
    case Text = 'text';
    case Csv = 'csv';
    case Docx = 'docx';
    case Xlsx = 'xlsx';
    case Pptx = 'pptx';

    public const string DOCX_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public const string XLSX_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const string PPTX_TYPE = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    /** @return list<string> lower-case, without the dot */
    public function extensions(): array
    {
        return match ($this) {
            self::Pdf => ['pdf'],
            self::Png => ['png'],
            self::Jpeg => ['jpg', 'jpeg'],
            self::Webp => ['webp'],
            self::Gif => ['gif'],
            self::Text => ['txt'],
            self::Csv => ['csv'],
            self::Docx => ['docx'],
            self::Xlsx => ['xlsx'],
            self::Pptx => ['pptx'],
        };
    }

    /** @return list<string> the content-detected media types this kind may present as */
    public function detectedTypes(): array
    {
        return match ($this) {
            self::Text, self::Csv => ['text/plain', 'text/csv'],
            default => [$this->mediaType()],
        };
    }

    /** The type stored for the asset and sent as `Content-Type` when it is served. */
    public function mediaType(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Png => 'image/png',
            self::Jpeg => 'image/jpeg',
            self::Webp => 'image/webp',
            self::Gif => 'image/gif',
            self::Text => 'text/plain',
            self::Csv => 'text/csv',
            self::Docx => self::DOCX_TYPE,
            self::Xlsx => self::XLSX_TYPE,
            self::Pptx => self::PPTX_TYPE,
        };
    }

    /** PDF and the raster images may be shown `inline` when asked; every other kind is always an attachment. */
    public function opensInline(): bool
    {
        return in_array($this, [self::Pdf, self::Png, self::Jpeg, self::Webp, self::Gif], true);
    }

    /**
     * The kind a file is, when its extension and its detected content agree on one; null refuses it.
     *
     * @param  string  $extension  the sanitised filename's extension, any case
     * @param  string  $detected  what the server detected from the content
     */
    public static function judge(string $extension, string $detected): ?self
    {
        $extension = strtolower($extension);
        $detected = strtolower(trim($detected));
        foreach (self::cases() as $kind) {
            if (in_array($extension, $kind->extensions(), true) && in_array($detected, $kind->detectedTypes(), true)) {
                return $kind;
            }
        }

        return null;
    }

    /** The kind a stored media type is, or null for a type the allowlist does not name (fail closed: such a file is never inline). */
    public static function ofMediaType(string $mediaType): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->mediaType() === $mediaType) {
                return $kind;
            }
        }

        return null;
    }

    /** @return list<string> every accepted extension, for messages and documentation */
    public static function allExtensions(): array
    {
        return array_merge(...array_map(static fn (self $k): array => $k->extensions(), self::cases()));
    }
}
