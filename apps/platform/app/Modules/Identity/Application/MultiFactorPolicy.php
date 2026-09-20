<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\Actor;

/**
 * Whether a person must sign in with a second factor. Answered by another module and registered from
 * its own provider: the same inversion as EffectiveCapabilities and AccountDeactivationGuard (ADR 0020),
 * for the same reason. Identity must not know what makes access privileged (that is Access's business)
 * and Access depends on Identity, not the reverse.
 *
 * It is AUTHENTICATION STRENGTH, not authorization. It grants nothing, and Identity never names a role
 * or a capability to decide it. Implementations answer from current persisted state, every time.
 */
interface MultiFactorPolicy
{
    public function requiredFor(Actor $actor): bool;
}
