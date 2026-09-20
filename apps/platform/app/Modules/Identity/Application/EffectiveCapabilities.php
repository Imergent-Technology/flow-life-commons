<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\Actor;

/**
 * The capabilities a signed-in human currently holds, as opaque identifiers.
 *
 * Identity reports them to the Console (login and `me`) without knowing what a capability
 * is or where they come from. Another module implements this and registers it from its own
 * provider: the same inversion as AccountDeactivationGuard (ADR 0020), and for the same
 * reason. Access depends on Identity, so Identity must not depend on Access, or the module
 * graph would close a cycle.
 *
 * Implementations must derive the answer fresh from current persisted state on every call.
 * It is never stored in the session and never taken from the client.
 */
interface EffectiveCapabilities
{
    /**
     * @return list<string> capability identifiers, in a stable order
     */
    public function for(Actor $actor): array;
}
