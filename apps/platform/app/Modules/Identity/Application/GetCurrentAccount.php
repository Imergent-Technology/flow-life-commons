<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\AuthenticationMethod;

final readonly class GetCurrentAccount
{
    public function __construct(
        private AccountRepository $accounts,
        private PersonRepository $people,
        private EffectiveCapabilities $capabilities,
        private MfaStatuses $mfa,
    ) {}

    /** @param  bool  $secondFactorVerified  the session was established with a second factor (the transport knows) */
    public function __invoke(AccountId $accountId, bool $secondFactorVerified = false): ?CurrentAccount
    {
        $account = $this->accounts->find($accountId);
        if ($account === null || ! $account->canAuthenticate()) {
            return null;
        }

        $person = $this->people->find($account->personId);
        if ($person === null) {
            return null;
        }

        $actor = Actor::user($account->id, $account->personId, $secondFactorVerified ? AuthenticationMethod::SessionWithSecondFactor : AuthenticationMethod::Session);

        return new CurrentAccount($actor, $account->email->value, $person->displayName, $this->capabilities->for($actor), $this->mfa->for($account->id));
    }
}
