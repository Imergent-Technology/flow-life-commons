<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\GrantSourcedRole;
use App\Modules\Access\Application\ProvisionableRole;
use App\Modules\Access\Application\RoleGrantSource;
use App\Modules\Relationships\Domain\RelationshipRecord;
use App\Shared\Domain\Actor;

/**
 * Grants the relationship's default role through Access (ADR 0038, K4, K11).
 *
 * The Person is the locked relationship row's subject. It is not the Actor, the route,
 * or the request body. Access stores that Person as given, because it cannot see Relationships.
 */
final readonly class GrantRelationshipRole
{
    public function __construct(private GrantSourcedRole $grant) {}

    /**
     * @throws RoleProvisioningNotPermitted
     */
    public function __invoke(Actor $actor, RelationshipRecord $relationship, ProvisionableRole $role): void
    {
        try {
            ($this->grant)(
                $actor,
                $relationship->personId,
                $role,
                RoleGrantSource::relationship($relationship->id->value),
            );
        } catch (AccessDenied) {
            throw new RoleProvisioningNotPermitted;
        }
    }
}
