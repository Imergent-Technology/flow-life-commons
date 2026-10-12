<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Relationships\Domain\RelationshipDefinition;

/** Shared reading of an optional `default_role` field (ADR 0038, K5, K7). */
final class DefaultRoleRequest
{
    public static function choice(bool $sent, mixed $value): ?DefaultRoleChoice
    {
        if (! $sent) {
            return null;
        }
        if ($value instanceof DefaultRoleChoice) {
            return $value;
        }

        return DefaultRoleChoice::fromRequest($value);
    }

    /**
     * @throws DefaultRoleNotApplicable
     * @throws DefaultRoleDecisionRequired
     */
    public static function guard(RelationshipDefinition $definition, string $targetStatus, ?DefaultRoleChoice $choice): void
    {
        $activates = $definition->defaultRole !== null && $definition->qualifies($targetStatus);
        if (! $activates && $choice !== null) {
            throw new DefaultRoleNotApplicable;
        }
        if ($activates && $choice === null) {
            throw new DefaultRoleDecisionRequired;
        }
    }
}
