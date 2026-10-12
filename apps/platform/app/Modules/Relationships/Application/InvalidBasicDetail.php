<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/**
 * A basic detail, or the shape of a request that carries them, breaks a rule (ADR 0038, P5, P7). `field` names it
 * (`display_name`, `primary_email`, `primary_phone`, or `new_person.email` and so on). The message never echoes a value.
 */
final class InvalidBasicDetail extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
