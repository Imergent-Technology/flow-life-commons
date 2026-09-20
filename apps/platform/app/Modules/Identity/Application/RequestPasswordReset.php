<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use Illuminate\Database\ConnectionInterface;

/**
 * "I forgot my password": issues a reset token to the Account's owner, by email, if there is an Account
 * that can be recovered. The caller is told the same thing either way (see the controller), so this
 * class's outcome is never a way to learn which addresses have accounts.
 *
 * - **Eligible** means the Account can sign in today: active, with a credential. An invited Account has
 *   no password to forget (letting reset serve it would make "reset" an account-provisioning path
 *   that bypasses the invitation), and a disabled one must not be recovered.
 * - **Serialised per Account.** The transaction takes the Account's row lock before anything else, so
 *   two simultaneous requests cannot both issue (the token table has one row per address, and an
 *   insert race would otherwise become a `500`). The second waits, sees a token was just issued, and
 *   does nothing.
 * - **No more than one token a minute** for an Account (the framework's throttle), so this cannot be
 *   used to flood someone's inbox even inside the rate limits.
 * - **The message is sent after the transaction commits**, and is never inside it: it is a network call.
 *   A delivery failure is the notifier's to record; it cannot change the public answer.
 * - **The raw token is never stored, logged or recorded.** Only its hash is persisted, and the audit
 *   event names the Account, not the token.
 *
 * Every non-throttled request is recorded as `password.reset_requested`: a success for an issued
 * token, otherwise a failure with a reason class and the identifier the caller claimed (no identity is
 * invented for an unknown address). Rate limited by source address and by canonical identifier.
 */
final readonly class RequestPasswordReset
{
    public function __construct(
        private AccountRepository $accounts,
        private PasswordResetTokens $tokens,
        private PasswordResetNotifier $notifier,
        private AttemptThrottle $throttle,
        private CredentialAudit $audit,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws TooManyAttempts the caller's rate limit is engaged
     */
    public function __invoke(EmailAddress $email, ClientContext $client): void
    {
        $block = $this->throttle->block(ThrottledAction::PasswordResetRequest, $client->ip, $email->canonical);
        if ($block !== null) {
            if ($block->auditable) {
                $this->audit->rateLimited(ThrottledAction::PasswordResetRequest, $block, $email, $client);
            }

            throw new TooManyAttempts($block->retryAfterSeconds);
        }
        $this->throttle->record(ThrottledAction::PasswordResetRequest, $client->ip, $email->canonical);

        $known = $this->accounts->findByEmail($email);
        if ($known === null || ! $known->canAuthenticate()) {
            $this->audit->passwordResetNotIssued(
                $known === null ? ResetFailure::UnknownAccount : ResetFailure::AccountNotEligible, $email, $known, $client,
            );

            return;
        }

        $outcome = $this->database->transaction(function () use ($known, $client): IssuedPasswordReset|ResetFailure {
            // Locked and re-read: what was true a moment ago proves nothing now.
            $account = $this->accounts->findForUpdate($known->id);
            if ($account === null || ! $account->canAuthenticate()) {
                return ResetFailure::AccountNotEligible;
            }
            if ($this->tokens->issuedRecently($account)) {
                return ResetFailure::RecentlyRequested;
            }

            $issued = $this->tokens->issue($account);
            $this->audit->passwordResetRequested($account, $client);

            return $issued;
        });

        if ($outcome instanceof ResetFailure) {
            $this->audit->passwordResetNotIssued($outcome, $email, $known, $client);

            return;
        }

        $this->notifier->send($known->email, $outcome);
    }
}
