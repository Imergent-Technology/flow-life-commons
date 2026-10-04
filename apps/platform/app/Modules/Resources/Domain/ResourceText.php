<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/**
 * The text rules Resources' names, titles and summaries share (ADR 0037): trimmed, one line, no control characters. Formatting
 * characters (ZWNJ, ZWJ, direction marks) stay: they are ordinary text in many languages and emoji sequences. Restated here,
 * as Crm and Discussions restate theirs, because a module may not use another's Domain.
 */
final class ResourceText
{
    public const int NAME_MAX = 80;

    public const int TITLE_MAX = 200;

    public const int SUMMARY_MAX = 300;

    /**
     * Trims ordinary whitespace only. PHP's default `trim` also strips NUL, which would quietly clean up a control character
     * that the one-line rule below must refuse.
     */
    public static function trimmed(string $value): string
    {
        return trim($value, " \t\n\r\v\f");
    }

    /** Trimmed with inner runs of whitespace collapsed to one space: "Training  guides" and "Training guides" are one name. */
    public static function collapsed(string $value): string
    {
        return self::trimmed((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @throws InvalidResourceInput */
    public static function singleLine(string $value, string $field, string $label, int $max): string
    {
        $value = self::trimmed($value);
        if ($value === '') {
            throw new InvalidResourceInput($field, "Give the {$label} a {$field}.");
        }

        return self::checked($value, $field, $label, $max);
    }

    /**
     * An optional line: blank is none.
     *
     * @throws InvalidResourceInput
     */
    public static function optionalLine(?string $value, string $field, string $label, int $max): ?string
    {
        if ($value === null || self::trimmed($value) === '') {
            return null;
        }

        return self::checked(self::trimmed($value), $field, $label, $max);
    }

    /** @throws InvalidResourceInput */
    private static function checked(string $value, string $field, string $label, int $max): string
    {
        $length = mb_check_encoding($value, 'UTF-8') ? mb_strlen($value) : $max + 1;
        if ($length > $max) {
            throw new InvalidResourceInput($field, "A {$label} {$field} is limited to {$max} characters.");
        }
        // One line: no control character (Unicode Cc, which includes tab and newline) and no line or paragraph separator.
        if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) === 1) {
            throw new InvalidResourceInput($field, "A {$label} {$field} is a single line and may not contain control characters.");
        }

        return $value;
    }
}
