<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\ResetMultiFactor;
use App\Modules\Identity\Application\SelfMfaResetProhibited;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/**
 * An operator recovers ANOTHER Account whose person has lost their authenticator and every recovery code. Needs
 * `identity.mfa.recover`. Identity's ResetMultiFactor does the work (and refuses the acting Account itself); the
 * target keeps their password, status and roles, and enrols a new authenticator at their next sign-in. Nothing secret
 * is generated or shown. This is not a way to remove administrator authority: the last-administrator invariant is
 * not involved, and a sole administrator who loses a factor is recovered by the server command.
 */
final readonly class ResetManagedMfa
{
    public function __construct(
        private AuthorizeAction $authorize,
        private ResetMultiFactor $reset,
        private AccountViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws AccountNotFound
     * @throws SelfMfaResetProhibited
     * @throws MfaNotEnrolled there was nothing to reset
     */
    public function __invoke(Actor $actor, AccountId $target): AccountView
    {
        ($this->authorize)($actor, Capability::RecoverMfa);

        if (! $this->reset->byAdministrator($actor, $target)->changed) {
            throw new MfaNotEnrolled;
        }

        return $this->views->of($target);
    }
}
