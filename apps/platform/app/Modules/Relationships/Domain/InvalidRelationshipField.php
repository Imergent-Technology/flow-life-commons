<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use RuntimeException;

/** A field value has the wrong type, breaks a constraint, or a required value is missing (ADR 0038, F7). */
final class InvalidRelationshipField extends RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('That value is not valid for this field.');
    }
}
