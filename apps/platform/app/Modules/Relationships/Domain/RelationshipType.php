<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

/**
 * A relationship type key issued by the catalog (ADR 0038, F2). There is no PHP enum of types:
 * a type the catalog loads through its source seam is a type, and a key the catalog does not
 * hold is not one. Callers obtain a type from `RelationshipCatalog::type`, never by parsing a string.
 */
final readonly class RelationshipType
{
    private function __construct(public string $key) {}

    /** Issued only while the catalog validates a definition. Not a parser for request input. */
    public static function issue(string $key): self
    {
        return new self($key);
    }

    public function equals(self $other): bool
    {
        return $this->key === $other->key;
    }
}
