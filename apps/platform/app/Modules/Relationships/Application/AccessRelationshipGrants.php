<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\RoleGrantSource;
use App\Modules\Access\Application\SourcedRoleGrantsOf;
use App\Modules\Access\Application\WithdrawSourcedRoles;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Shared\Domain\Actor;

/**
 * Withdraws grants sourced from one relationship, inside the caller's transaction (ADR 0038, K4, K11).
 *
 * It authorizes nothing. Inactivation and deletion must not be blocked by a missing
 * `access.roles.assign`. Only this source's grants are removed. Independent assignments
 * and every other source stay. No rows records nothing.
 */
final readonly class AccessRelationshipGrants implements RelationshipGrantWithdrawal
{
    public function __construct(
        private SourcedRoleGrantsOf $grants,
        private WithdrawSourcedRoles $withdraw,
    ) {}

    public function withdraw(Actor $actor, RelationshipId $relationship): int
    {
        $source = RoleGrantSource::relationship($relationship->value);
        $existing = ($this->grants)([$source]);
        ($this->withdraw)($actor, $source);

        return count($existing);
    }
}
