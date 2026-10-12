<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipId;
use App\Shared\Domain\Actor;

/**
 * Withdraws grants sourced from one relationship, inside the caller's transaction (ADR 0038, F12, K11).
 * The production binding is Access's withdrawal. It joins this transaction, removes only that
 * source's grants, and must never block inactivation or deletion by asking for a grant capability.
 */
interface RelationshipGrantWithdrawal
{
    public function withdraw(Actor $actor, RelationshipId $relationship): int;
}
