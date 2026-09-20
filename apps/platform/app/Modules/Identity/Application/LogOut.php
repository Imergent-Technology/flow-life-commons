<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/**
 * Records that a session ended by the user's own action. It changes nothing about the
 * Account or Person; ending the session itself is the transport's job.
 *
 * With no authenticated session there is nothing to record, so it is safe to call
 * repeatedly or after the session has already lapsed.
 */
final readonly class LogOut
{
    public function __construct(
        private AccountRepository $accounts,
        private AuthenticationAudit $audit,
    ) {}

    public function __invoke(?AccountId $accountId, ClientContext $client): void
    {
        if ($accountId === null) {
            return;
        }

        $account = $this->accounts->find($accountId);
        $actor = $account !== null && $account->canAuthenticate() ? Actor::user($account->id, $account->personId) : null;

        $this->audit->loggedOut($actor, $account?->personId, $accountId, $client);
    }
}
