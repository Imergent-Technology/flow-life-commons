<?php

declare(strict_types=1);

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Capability;
use App\Modules\Identity\Application\ResolveActor;
use App\Shared\Domain\AccountId;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * The Laravel Gate as an enforcement edge and nothing more. It defines no rule of its
 * own: it turns the authenticated user into an Actor through Identity, then asks the
 * Authorizer. Everything that decides an outcome lives in Access's Application layer.
 */
final readonly class CapabilityGate
{
    public function __construct(
        private ResolveActor $resolveActor,
        private Authorizer $authorizer,
    ) {}

    public function allows(Authenticatable $user, Capability $capability): bool
    {
        $identifier = $user->getAuthIdentifier();
        if (! is_string($identifier)) {
            return false;
        }

        try {
            $actor = ($this->resolveActor)(AccountId::fromString($identifier));
        } catch (InvalidArgumentException) {
            return false;
        }

        return $actor !== null && $this->authorizer->allows($actor, $capability);
    }
}
