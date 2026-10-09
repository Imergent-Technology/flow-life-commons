<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Domain;

/** One lifecycle state (ADR 0038, F9). `qualifies` is the only eligibility fact a state carries. */
final readonly class RelationshipState
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $tone,
        public bool $qualifies,
    ) {}
}
