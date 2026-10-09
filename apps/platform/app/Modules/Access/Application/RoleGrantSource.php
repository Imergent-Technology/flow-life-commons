<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** One source instance a grant is attributed to. The id is the source's own id, not a Person id. */
final readonly class RoleGrantSource
{
    public string $id;

    public function __construct(
        public RoleGrantSourceType $type,
        string $id,
    ) {
        if (! Str::isUlid($id)) {
            throw new InvalidArgumentException('A grant source id is a ULID.');
        }

        $this->id = strtolower($id);
    }

    public static function relationship(string $id): self
    {
        return new self(RoleGrantSourceType::Relationship, $id);
    }
}
