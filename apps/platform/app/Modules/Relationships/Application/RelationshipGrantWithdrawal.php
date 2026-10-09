<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipId;
use App\Shared\Domain\Actor;

/**
 * Withdraws grants sourced from one relationship, inside the caller's transaction (ADR 0038, F12).
 * WP1 has no sourced grants. The production binding removes nothing and returns zero. WP2B replaces
 * it with Access's withdrawal, which must join this transaction and must never block deletion.
 */
interface RelationshipGrantWithdrawal
{
    public function withdraw(Actor $actor, RelationshipId $relationship): int;
}
