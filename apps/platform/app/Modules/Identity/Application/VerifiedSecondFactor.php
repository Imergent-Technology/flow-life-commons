<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** A second factor was accepted. `recoveryCodesRemaining` is set only when a recovery code was spent. */
final readonly class VerifiedSecondFactor
{
    public function __construct(
        public SecondFactorMethod $method,
        public ?int $recoveryCodesRemaining = null,
    ) {}
}
