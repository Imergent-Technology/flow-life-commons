<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure;

use App\Modules\Identity\Application\EffectiveCapabilities;
use App\Shared\Domain\Actor;

/**
 * Identity's default when no module supplies capabilities: none. The platform registers
 * Access's implementation over this; a test asserts that it does.
 */
final readonly class NoEffectiveCapabilities implements EffectiveCapabilities
{
    public function for(Actor $actor): array
    {
        return [];
    }
}
