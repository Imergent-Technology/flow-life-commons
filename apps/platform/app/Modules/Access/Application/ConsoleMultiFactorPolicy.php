<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\MultiFactorPolicy;
use App\Shared\Domain\Actor;

/**
 * Access's answer to Identity's MultiFactorPolicy port (ADR 0023), registered from Access's own provider:
 * a person must sign in with a second factor when their access reaches the privileged Console.
 *
 * It asks one thing of the Authorizer: does this person CURRENTLY hold `console.access`? It never looks at
 * a role, and it grants nothing. Roles are how the capability is held; the requirement is tied to the
 * surface being protected, so it stays right when roles change and when more roles carry the capability.
 * Answered fresh from current assignments every time.
 */
final readonly class ConsoleMultiFactorPolicy implements MultiFactorPolicy
{
    public function __construct(private Authorizer $authorizer) {}

    public function requiredFor(Actor $actor): bool
    {
        return $this->authorizer->allows($actor, Capability::ConsoleAccess);
    }
}
