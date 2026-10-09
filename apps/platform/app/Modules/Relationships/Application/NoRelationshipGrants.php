<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipId;
use App\Shared\Domain\Actor;

/** WP1's grant withdrawal: there is no grant table yet, so there is nothing to withdraw. */
final readonly class NoRelationshipGrants implements RelationshipGrantWithdrawal
{
    public function withdraw(Actor $actor, RelationshipId $relationship): int
    {
        return 0;
    }
}
