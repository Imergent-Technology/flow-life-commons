<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * Global organizational metadata (ADR 0037, decisions 6-7): a name and a place in the order. Not an audience and not
 * authorization: how a consumer presents Categories is the consumer's business. "Training guides" and "training  Guides" are one
 * name (`nameCanonical` carries the uniqueness).
 */
final readonly class Category
{
    private function __construct(
        public CategoryId $id,
        public string $name,
        public string $nameCanonical,
        public int $position,
        public Provenance $provenance,
    ) {}

    /** @throws InvalidResourceInput */
    public static function create(CategoryId $id, string $name, int $position, PersonId $by, DateTimeImmutable $now): self
    {
        $name = self::normalise($name);

        return new self($id, $name, self::canonical($name), $position, Provenance::created($by, $now));
    }

    public static function reconstitute(CategoryId $id, string $name, string $nameCanonical, int $position, Provenance $provenance): self
    {
        return new self($id, $name, $nameCanonical, $position, $provenance);
    }

    /** @throws InvalidResourceInput */
    public function renamed(string $name, PersonId $by, DateTimeImmutable $now): self
    {
        $name = self::normalise($name);

        return new self($this->id, $name, self::canonical($name), $this->position, $this->provenance->touched($by, $now));
    }

    public static function canonical(string $name): string
    {
        return mb_strtolower(ResourceText::collapsed($name));
    }

    /** @throws InvalidResourceInput */
    private static function normalise(string $name): string
    {
        return ResourceText::singleLine(ResourceText::collapsed($name), 'name', 'Category', ResourceText::NAME_MAX);
    }
}
