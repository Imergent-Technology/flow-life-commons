<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

final readonly class RelationshipCandidate
{
    public function __construct(
        public NamedPerson $person,
        public string $matchedOn,
        public ?string $status,
    ) {}
}
