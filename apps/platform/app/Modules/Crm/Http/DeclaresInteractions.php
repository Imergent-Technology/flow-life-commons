<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Domain\Interaction;
use App\Modules\Crm\Domain\InteractionKind;
use Closure;
use DateTimeImmutable;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** The shape of an interaction's fields, shared by "record" and "edit" so they cannot drift. */
trait DeclaresInteractions
{
    /** ISO 8601 with an explicit offset (`Z` allowed), with or without fractional seconds, as `Date#toISOString` writes it. */
    private const array INSTANT_FORMATS = ['Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.vP'];

    /** @return array<string, list<mixed>> */
    private function interactionRules(bool $required): array
    {
        $presence = $required ? ['required'] : ['sometimes', 'required'];

        return [
            'kind' => $required ? ['sometimes', Rule::enum(InteractionKind::class)] : ['sometimes', 'required', Rule::enum(InteractionKind::class)],
            'body' => [...$presence, 'string', 'max:'.(Interaction::MAX_BODY_LENGTH * 4)],
            'occurred_at' => ['sometimes', ...($required ? ['nullable'] : ['required']), 'string', $this->instantRule(...)],
        ];
    }

    /**
     * Laravel's `date_format` compares the re-formatted date with the input, which refuses `Z` for an offset of zero; this
     * accepts it, and refuses a date PHP would roll over (`2026-02-31`).
     *
     * @param  Closure(string): mixed  $fail
     */
    private function instantRule(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $this->parseInstant($value) === null) {
            $fail('Send a date and time like 2026-09-30T14:30:00Z, with a UTC offset.');
        }
    }

    private function parseInstant(string $value): ?DateTimeImmutable
    {
        foreach (self::INSTANT_FORMATS as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed;
            }
        }

        return null;
    }

    private function instant(string $value): DateTimeImmutable
    {
        return $this->parseInstant($value) ?? throw new InvalidArgumentException('Validated instant did not parse.');
    }
}
