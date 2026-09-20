<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

final readonly class GetCurrentAccount
{
    public function __construct(
        private AccountRepository $accounts,
        private PersonRepository $people,
    ) {}

    public function __invoke(AccountId $accountId): ?CurrentAccount
    {
        $account = $this->accounts->find($accountId);
        if ($account === null || ! $account->canAuthenticate()) {
            return null;
        }

        $person = $this->people->find($account->personId);

        return $person === null
            ? null
            : new CurrentAccount(Actor::user($account->id, $account->personId), $account->email->value, $person->displayName);
    }
}
