<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\EffectiveCapabilities;
use App\Shared\Domain\Actor;

/**
 * Access's answer to Identity's EffectiveCapabilities port, registered from Access's own
 * provider (the ADR 0020 inversion): Identity reports what a person may do without
 * knowing Access exists. Always fresh, always through the Authorizer.
 */
final readonly class AuthorizerEffectiveCapabilities implements EffectiveCapabilities
{
    public function __construct(private Authorizer $authorizer) {}

    public function for(Actor $actor): array
    {
        return array_map(
            static fn (Capability $capability): string => $capability->value,
            $this->authorizer->capabilitiesOf($actor),
        );
    }
}
