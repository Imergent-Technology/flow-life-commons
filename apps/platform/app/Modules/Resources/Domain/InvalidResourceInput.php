<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use InvalidArgumentException;

/**
 * Input that breaks a Resources rule: a blank or over-long title, a bad URI, a document outside the rich-content profile.
 * Carries the field the message is about and a stable `code` for the HTTP edge (`invalid_uri`, `invalid_content`, or the general
 * `invalid_resource_input`), and nothing else: it never echoes the offending value.
 */
final class InvalidResourceInput extends InvalidArgumentException
{
    public const string GENERAL = 'invalid_resource_input';

    public const string URI = 'invalid_uri';

    public const string CONTENT = 'invalid_content';

    public function __construct(public readonly string $field, string $message, public readonly string $problem = self::GENERAL)
    {
        parent::__construct($message);
    }
}
