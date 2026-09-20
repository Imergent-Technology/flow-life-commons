<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountRepository;
use App\Shared\Domain\AccountId;

/**
 * For a session that was established WITHOUT a second factor: does its Account need one now?
 *
 * A password-only session is legitimate for an Account whose access does not need more. But that can
 * change while the session lives (a role granting Console access is assigned to it, or an authenticator
 * is enrolled elsewhere), and a session that never proved a second factor must not become a privileged
 * one by that change. Asked on each request such a session makes; when the answer is yes the transport
 * ends the session and this records it, and the next sign-in goes through enrolment or the challenge.
 *
 * Nothing is cached: the answer comes from current state every time.
 */
final readonly class EndSessionWithoutSecondFactor
{
    public function __construct(
        private AccountRepository $accounts,
        private SecondFactorRequirement $requirement,
        private MfaAudit $audit,
    ) {}

    /** True when the session must end (and the event has been recorded). */
    public function __invoke(AccountId $accountId, ClientContext $client): bool
    {
        $account = $this->accounts->find($accountId);
        if ($account === null || ! $account->canAuthenticate() || $this->requirement->for($account) === SecondFactorNeed::None) {
            return false;
        }

        $this->audit->sessionSecondFactorRequired($account, $client);

        return true;
    }
}
