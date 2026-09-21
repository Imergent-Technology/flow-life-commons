<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/** What one maintenance sweep removed. Counts only: nothing here identifies a person or a secret. */
final readonly class PrunedState
{
    public function __construct(
        public int $sessions,
        public int $passwordResetTokens,
        public int $pendingAuthenticators,
    ) {}

    public function total(): int
    {
        return $this->sessions + $this->passwordResetTokens + $this->pendingAuthenticators;
    }
}
