<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** A directory or candidate query is outside the bounds the contract allows. Nothing was searched past the bound. */
final class InvalidRelationshipQuery extends RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
