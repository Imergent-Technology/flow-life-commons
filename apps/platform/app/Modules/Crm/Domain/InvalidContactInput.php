<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

use InvalidArgumentException;

/**
 * CRM data that breaks a CRM rule (an email with no @, a tag name that is empty). Carries the field the message is
 * about so the HTTP edge can attach it, and nothing else: it never echoes the offending value.
 */
final class InvalidContactInput extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
