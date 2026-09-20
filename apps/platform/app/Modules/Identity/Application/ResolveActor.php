<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\AuthenticationMethod;

/**
 * Turns "this session belongs to account X" into an Actor, re-checked against CURRENT
 * persisted state: an Account that is no longer active resolves to nothing. The Actor
 * carries identity only, so a captured one can never preserve revoked privileges.
 */
final readonly class ResolveActor
{
    public function __construct(private AccountRepository $accounts) {}

    /** @param  bool  $secondFactorVerified  the session was established with a second factor (the transport knows) */
    public function __invoke(AccountId $accountId, bool $secondFactorVerified = false): ?Actor
    {
        $account = $this->accounts->find($accountId);

        return $account !== null && $account->canAuthenticate()
            ? Actor::user($account->id, $account->personId, $secondFactorVerified ? AuthenticationMethod::SessionWithSecondFactor : AuthenticationMethod::Session)
            : null;
    }
}
