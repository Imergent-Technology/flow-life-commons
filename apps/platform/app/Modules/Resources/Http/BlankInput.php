<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

/**
 * What a blank scalar means to a Resources management request.
 *
 * The management routes are exempt from Laravel's TrimStrings and ConvertEmptyStringsToNull, because whitespace inside a rich-content
 * document is content (bootstrap/app.php). The price is that `""` now reaches the request as `""`, and Laravel skips every rule that is
 * not implicit (`ulid`, `array`, `Rule::enum`) for a blank string, so a blank passes validation and would reach a value-object
 * constructor (`CategoryId::fromString`, `Audience::from`) and fail there. These requests therefore decide it themselves, with the
 * very test Laravel uses to skip the rules (PHP's `trim`), so what validation let through is exactly what is handled here.
 *
 * - An OPTIONAL id or filter that is blank is ABSENT: the same as null, as it was before the exemption (`category_id: ""` clears).
 * - A REQUIRED list (`ids`, `audiences`) that is blank becomes null, so its `array` rule fails and the answer is a 422.
 *
 * Never applied to the rich-content document, nor to titles, names or summaries, which the domain trims and judges itself.
 */
final class BlankInput
{
    public static function isBlank(mixed $value): bool
    {
        return is_string($value) && trim($value) === '';
    }

    /** The string as sent, or null when it is absent or blank. Not trimmed: padding around a real value is for validation to refuse. */
    public static function optional(mixed $value): ?string
    {
        return is_string($value) && ! self::isBlank($value) ? $value : null;
    }
}
