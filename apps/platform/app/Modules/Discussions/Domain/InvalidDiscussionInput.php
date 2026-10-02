<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Domain;

use InvalidArgumentException;

/**
 * Discussion input that breaks a Discussions rule (a blank or over-long title, a body with control characters). Carries the
 * field the message is about so the HTTP edge can attach it, and nothing else: it never echoes the offending value.
 */
final class InvalidDiscussionInput extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
