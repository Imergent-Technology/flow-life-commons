<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Stringable;

/**
 * Base for typed aggregate identifiers (ADR 0006): a 26-character ULID generated in
 * application code before persistence.
 *
 * The value is always held lowercase. MariaDB compares CHAR columns case-insensitively
 * and PostgreSQL does not, so a single canonical form is what keeps lookups by id
 * identical on both engines. It is also the form Laravel's HasUlids generates.
 *
 * Typed subclasses exist so a person id can never be passed where an account id is
 * expected. An id is not a secret or a capability; it embeds its creation time.
 */
abstract readonly class UlidIdentifier implements Stringable
{
    final private function __construct(public string $value) {}

    public static function generate(): static
    {
        return new static(strtolower((string) Str::ulid()));
    }

    public static function fromString(string $value): static
    {
        if (! Str::isUlid($value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid %s.', $value, static::class));
        }

        return new static(strtolower($value));
    }

    public function equals(self $other): bool
    {
        return $other::class === static::class && $other->value === $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
