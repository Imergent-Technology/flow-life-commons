<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

/** One metadata field of a relationship type (ADR 0038, F4, F7). Data only: nothing here is executable. */
final readonly class RelationshipField
{
    /**
     * @param  list<string>  $options  choice keys; empty unless the value type is choice
     */
    public function __construct(
        public string $key,
        public string $valueType,
        public ?int $maxLength,
        public ?string $notAfter,
        public bool $required,
        public string $visibility,
        public string $label,
        public ?string $help,
        public int $order,
        public ?string $group,
        public array $options,
    ) {}

    public function visibleTo(string $capability): bool
    {
        return $capability === 'manage' || $this->visibility === 'view';
    }
}
