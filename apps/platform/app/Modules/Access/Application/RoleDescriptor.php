<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/**
 * A system role as an operator's screen may show it: a stable key, words, and what it grants. Access owns this
 * vocabulary and hands it out as DATA; the Console renders it and defines no role of its own, so a role added or
 * renamed here needs no Console change. The capabilities are informational: nothing decides from them but Access.
 */
final readonly class RoleDescriptor
{
    /** @param  list<string>  $capabilities */
    private function __construct(
        public string $key,
        public string $name,
        public string $description,
        public array $capabilities,
    ) {}

    public static function of(Role $role): self
    {
        $capabilities = array_map(static fn (Capability $c): string => $c->value, $role->capabilities());
        sort($capabilities, SORT_STRING);

        return new self($role->value, $role->displayName(), $role->description(), $capabilities);
    }
}
