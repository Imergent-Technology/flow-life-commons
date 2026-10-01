<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

/**
 * The kinds of contact method Phase 1 records. Stored as its string value; a new kind is a deliberate addition here,
 * with its own normalisation, not a free-text column.
 */
enum ContactMethodKind: string
{
    case Email = 'email';
    case Phone = 'phone';

    public const int MAX_VALUE_LENGTH = 255;

    /**
     * What the human entered, trimmed, after checking it is plausibly this kind of thing. Deliberately permissive:
     * CRM records what a Guardian was told, it does not verify deliverability or ownership.
     *
     * @throws InvalidContactInput
     */
    public function display(string $entered): string
    {
        $value = trim($entered);
        if ($value === '' || mb_strlen($value) > self::MAX_VALUE_LENGTH || preg_match('/[\p{C}]/u', $value) === 1) {
            throw new InvalidContactInput('value', 'Enter a value of up to 255 characters, with no control characters.');
        }

        return match ($this) {
            self::Email => preg_match('/^[^@\s]+@[^@\s]+$/u', $value) === 1
                ? $value
                : throw new InvalidContactInput('value', 'Enter an email address like name@example.org.'),
            self::Phone => preg_match_all('/[0-9]/', $value) >= 3 && mb_strlen($value) <= 64
                ? preg_replace('/\s+/u', ' ', $value) ?? $value
                : throw new InvalidContactInput('value', 'Enter a phone number of up to 64 characters with at least 3 digits.'),
        };
    }

    /**
     * The value used to MATCH and SEARCH, never to say who someone is.
     *
     * - Email: lower-cased. Nothing else: no plus-address stripping, no provider-specific alias rules, so two
     *   differently written addresses are never assumed to be one mailbox.
     * - Phone: the digits, with a leading plus kept if the entry began with one. No country code is inferred and no
     *   E.164 identity is claimed, so "+1 555 0100" and "555 0100" do not match, and an extension ("x22") simply
     *   adds its digits. It makes "(555) 010-0100" findable as "5550100100", and no more than that.
     */
    public function searchValue(string $display): string
    {
        return match ($this) {
            self::Email => mb_strtolower($display),
            self::Phone => (str_starts_with($display, '+') ? '+' : '').preg_replace('/[^0-9]/', '', $display),
        };
    }
}
