<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** A login limit is engaged. `scope` is for the audit trail only, never the caller. */
final readonly class ThrottleBlock
{
    public function __construct(
        public string $scope,
        public int $retryAfterSeconds,
        /** False once this engagement has already been audited recently. */
        public bool $auditable,
    ) {}
}
