<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;

/**
 * An operator asks for the SAME password-reset email the Account holder would get from "I forgot my password". It is
 * not a second reset mechanism: it issues through the same `PasswordResetTokens` port and delivers through the same
 * `PasswordResetNotifier`, so the token, its lifetime, its one-live-token-per-Account rule, the message, the link and the
 * reset page, and what completing a reset does to sessions, are all the existing ones.
 *
 * - **The operator sees nothing secret.** No token, no link, no password: the result is only whether the message went.
 *   Only the notifier ever reveals the token, to the Account's own address, after the commit.
 * - **Only an Account that can sign in.** An INVITED Account has no password to reset (a new invitation is the remedy,
 *   or this would become a way round the invitation) and a DISABLED one must not be recovered. Refused with a reason.
 * - **Serialised and limited like the public flow:** the Account's row is locked and re-read, and a token issued in the
 *   last minute is refused (`RecentlyRequested`), so this cannot flood someone's inbox.
 * - **Audited in the same transaction** as `password.reset_requested_by_operator`, naming the operator and the Account
 *   and nothing secret; if the audit write fails the token is rolled back and nothing is sent.
 * - **The message goes after the commit**, never inside it. A delivery failure is reported to the caller and recorded
 *   as `password.reset_delivery_failed` (the fact only); the token is stored but unreachable, and asking again replaces it.
 *
 * **This use case does not authorize its caller** (Identity cannot ask Access). Access's use case does, first. It does
 * not touch the Account's password, sessions, second factor or security generation: completing the reset does, as always.
 */
final readonly class IssueAdministrativePasswordReset
{
    public function __construct(
        private AccountRepository $accounts,
        private PasswordResetTokens $tokens,
        private PasswordResetNotifier $notifier,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccountNotFound
     * @throws PasswordResetNotIssuable
     */
    public function __invoke(AccountId $accountId, Actor $by): PasswordResetDelivery
    {
        $issued = $this->database->transaction(function () use ($accountId, $by): array {
            // Locked and re-read: what was true a moment ago proves nothing now.
            $account = $this->accounts->findForUpdate($accountId) ?? throw new AccountNotFound;
            if ($account->status === AccountStatus::Invited) {
                throw new PasswordResetNotIssuable(PasswordResetRefusal::AccountInvited);
            }
            if (! $account->canAuthenticate()) {
                throw new PasswordResetNotIssuable(PasswordResetRefusal::AccountDisabled);
            }
            if ($this->tokens->issuedRecently($account)) {
                throw new PasswordResetNotIssuable(PasswordResetRefusal::RecentlyRequested);
            }

            $reset = $this->tokens->issue($account);
            ($this->record)(
                IdentityEvent::PasswordResetRequestedByOperator->value, SecurityEventOutcome::Success,
                $by, $account->personId, $account->id, null, null,
            );

            return [$account, $reset];
        }, 3);

        [$account, $reset] = $issued;
        $delivery = $this->notifier->send($account->email, $reset);

        if ($delivery === PasswordResetDelivery::Failed) {
            ($this->record)(
                IdentityEvent::PasswordResetDeliveryFailed->value, SecurityEventOutcome::Failure,
                $by, $account->personId, $account->id, null, null,
                ['action' => 'operator'],
            );
        }

        return $delivery;
    }
}
