<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Canonical metadata values (ADR 0038, F7). One validator for every write. A stored value is
 * never empty: clearing a field deletes its row, which the caller does when the canonical
 * result is null. The date bound is the current date at UTC+14, so a date that is already
 * "today" for an operator anywhere is accepted.
 */
final class FieldValues
{
    /**
     * @param  array<mixed, mixed>  $input  partial: only the keys sent; null clears
     * @return array<string, string|null> canonical text, or null to clear
     *
     * @throws UnknownRelationshipField
     * @throws InvalidRelationshipField
     */
    public static function parse(RelationshipDefinition $definition, array $input, DateTimeImmutable $now): array
    {
        $parsed = [];
        foreach ($input as $key => $value) {
            $field = is_string($key) ? $definition->field($key) : null;
            if (! is_string($key) || $field === null) {
                throw new UnknownRelationshipField(is_string($key) ? $key : '');
            }
            $parsed[$key] = $value === null ? null : self::canonical($field, $value, $now);
        }

        return $parsed;
    }

    /**
     * A required field that the write does not leave with a value. Partial updates pass the
     * fields that remain after the change, not only the keys that were sent.
     *
     * @param  array<string, string|null>  $fields
     */
    public static function missingRequired(RelationshipDefinition $definition, array $fields): ?string
    {
        foreach ($definition->fields as $field) {
            if ($field->required && ($fields[$field->key] ?? null) === null) {
                return $field->key;
            }
        }

        return null;
    }

    /**
     * @throws InvalidRelationshipField
     */
    public static function canonical(RelationshipField $field, mixed $value, DateTimeImmutable $now): string
    {
        $canonical = match ($field->valueType) {
            'text', 'long_text' => self::text($field, $value),
            'date' => self::date($field, $value, $now),
            'boolean' => self::boolean($field, $value),
            'choice' => self::choice($field, $value),
            default => throw new InvalidRelationshipField($field->key),
        };
        if ($canonical === null) {
            throw new InvalidRelationshipField($field->key);
        }

        return $canonical;
    }

    /** Whether a value already stored in canonical form still satisfies the field. Never throws. */
    public static function acceptsStored(RelationshipField $field, string $stored, DateTimeImmutable $now): bool
    {
        $input = match ($field->valueType) {
            'boolean' => match ($stored) {
                'true' => true,
                'false' => false,
                default => null,
            },
            default => $stored,
        };
        if ($input === null) {
            return false;
        }
        try {
            return self::canonical($field, $input, $now) === $stored;
        } catch (InvalidRelationshipField) {
            return false;
        }
    }

    /** The latest calendar date that `not_after: today` accepts, which is today at UTC+14. */
    public static function latestDate(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone('UTC'))->modify('+14 hours')->format('Y-m-d');
    }

    private static function text(RelationshipField $field, mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || mb_strlen($trimmed) > ($field->maxLength ?? 0)) {
            return null;
        }
        $forbidden = $field->valueType === 'long_text' ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
        if (preg_match($forbidden, $trimmed) === 1) {
            return null;
        }

        return $trimmed;
    }

    private static function date(RelationshipField $field, mixed $value, DateTimeImmutable $now): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        [$year, $month, $day] = array_map(intval(...), explode('-', $value));
        if (! checkdate($month, $day, $year)) {
            return null;
        }
        if ($field->notAfter === 'today' && $value > self::latestDate($now)) {
            return null;
        }

        return $value;
    }

    private static function boolean(RelationshipField $field, mixed $value): ?string
    {
        if ($field->valueType !== 'boolean' || ! is_bool($value)) {
            return null;
        }

        return $value ? 'true' : 'false';
    }

    private static function choice(RelationshipField $field, mixed $value): ?string
    {
        if (! is_string($value) || ! in_array($value, $field->options, true)) {
            return null;
        }

        return $value;
    }
}
