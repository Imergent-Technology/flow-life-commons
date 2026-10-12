<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use RuntimeException;

/**
 * A basic detail that breaks CRM's or Identity's own rule for it. `field` is the detail it is about (`display_name`,
 * `primary_email`, `primary_phone`, or `email` / `phone` for a new Person). It never carries the offending value.
 */
final class InvalidPersonBasics extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
