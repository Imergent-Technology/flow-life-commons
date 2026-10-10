<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

/**
 * What an operator explicitly asked to do with a type's default role (ADR 0038, K5).
 * The server never infers one of these from a missing field or a stored decision.
 */
enum DefaultRoleChoice: string
{
    case Grant = 'grant';
    case Decline = 'decline';
    case Defer = 'defer';

    public static function fromRequest(mixed $value): self
    {
        if (! is_string($value)) {
            throw new InvalidDefaultRoleChoice;
        }

        return self::tryFrom($value) ?? throw new InvalidDefaultRoleChoice;
    }
}
