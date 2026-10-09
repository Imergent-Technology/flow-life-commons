<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

/** The closed vocabulary of things that may source a role grant (ADR 0038, K4). */
enum RoleGrantSourceType: string
{
    case Relationship = 'relationship';

    public function label(): string
    {
        return match ($this) {
            self::Relationship => 'Granted through a relationship',
        };
    }
}
