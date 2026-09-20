<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Shared\Domain\Actor;

/**
 * What a correct password must be followed by, for this Account, right now.
 *
 * - An Account with a proved authenticator is always challenged: having one means using it.
 * - An Account without one is asked to enrol if, and only if, the policy (Access's, through the port)
 *   says its access is privileged. Identity does not decide that, and never looks at a role.
 * - Otherwise a password is enough.
 */
final readonly class SecondFactorRequirement
{
    public function __construct(
        private TotpFactorRepository $factors,
        private MultiFactorPolicy $policy,
    ) {}

    public function for(Account $account): SecondFactorNeed
    {
        if ($this->factors->findByAccount($account->id)?->isActive() === true) {
            return SecondFactorNeed::Challenge;
        }

        return $this->policy->requiredFor(Actor::user($account->id, $account->personId))
            ? SecondFactorNeed::Enrollment
            : SecondFactorNeed::None;
    }
}
