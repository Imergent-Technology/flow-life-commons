<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountRepository;
use App\Shared\Domain\AccountId;

/** Records that an authenticated session was ended by the platform's absolute-lifetime rule. */
final readonly class ExpireSession
{
    public function __construct(
        private AccountRepository $accounts,
        private AuthenticationAudit $audit,
    ) {}

    public function __invoke(AccountId $accountId, ExpiryReason $reason, int $lifetimeMinutes, ClientContext $client): void
    {
        $this->audit->sessionExpired($this->accounts->find($accountId)?->personId, $accountId, $reason, $lifetimeMinutes, $client);
    }
}
