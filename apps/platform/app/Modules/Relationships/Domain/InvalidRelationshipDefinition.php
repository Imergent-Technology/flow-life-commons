<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

use RuntimeException;

/**
 * A definition document, or a set of them, broke a catalog rule (ADR 0038, F3, F5).
 * The message names the document and the rule. In production this stops the application
 * from booting; a type that fails to load must not silently disappear.
 */
final class InvalidRelationshipDefinition extends RuntimeException
{
    public function __construct(public readonly string $document, public readonly string $rule)
    {
        parent::__construct("Relationship definition \"{$document}\" is invalid: {$rule}.");
    }
}
