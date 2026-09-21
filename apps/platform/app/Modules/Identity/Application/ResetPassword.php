<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\PlainPassword;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Completes "I forgot my password": the owner presents the emailed token with a new password.
 *
 * It does NOT sign them in, and it ends every session the Account has (a reset is what someone does
 * when a credential may be compromised). It cannot activate an invited Account or re-enable a
 * disabled one: only an Account that can sign in today qualifies.
 *
 * - **Before the transaction:** rate limit, then everything that needs the network or is slow: the
 *   password policy (including the breached-password check) and hashing the new password. A password
 *   that fails the policy does not consume the token.
 * - **In the transaction, and first** (nothing is read before it: a plain read on InnoDB fixes a
 *   snapshot that a lock taken afterwards does not move past): lock and re-read the Account, require it
 *   still eligible, then re-validate the token against it. Two resets with one token serialise on the
 *   Account's lock and the second finds the token gone; a disable that commits first is seen and wins.
 *   Then set the password, delete the token, delete the Account's sessions, advance its security
 *   generation (ADR 0025, so a sign-in that proved the old credential cannot establish a session after
 *   this commits), and record `password.reset_completed`, all committed together or not at all.
 * - **One answer for every failure** (ResetRejected), and the same hashing work on every path (a
 *   throwaway check when there is no Account), so neither the response nor its timing says whether an
 *   address has an Account. A failure is recorded as `password.reset_failed` with a reason class, after
 *   the transaction (a failure is not thrown from inside one, so its event is kept).
 */
final readonly class ResetPassword
{
    public function __construct(
        private AccountRepository $accounts,
        private PasswordResetTokens $tokens,
        private AccountSessions $sessions,
        private AccountSecurityGeneration $generations,
        private PasswordPolicy $policy,
        private PasswordHasher $passwords,
        private AttemptThrottle $throttle,
        private CredentialAudit $audit,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws TooManyAttempts the caller's rate limit is engaged
     * @throws PasswordRejected the new password does not meet the policy
     * @throws CompromisedPasswordCheckUnavailable the breach check could not be completed; retry
     * @throws ResetRejected the reset cannot be completed, for any reason
     */
    public function __invoke(EmailAddress $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password, ClientContext $client): void
    {
        $block = $this->throttle->block(ThrottledAction::PasswordResetCompletion, $client->ip, $email->canonical);
        if ($block !== null) {
            if ($block->auditable) {
                $this->audit->rateLimited(ThrottledAction::PasswordResetCompletion, $block, $email, $client);
            }

            throw new TooManyAttempts($block->retryAfterSeconds);
        }
        $this->throttle->record(ThrottledAction::PasswordResetCompletion, $client->ip, $email->canonical);

        $plain = PlainPassword::fromInput($password);
        $this->policy->assertAcceptable($plain);
        $hash = $this->passwords->hash($plain);

        // Read here, OUTSIDE the transaction, only to learn which row to lock: the first statement
        // inside it must be the lock itself.
        $known = $this->accounts->findByEmail($email);
        if ($known === null) {
            $this->tokens->spendDecoyCheck($token);
            $this->audit->passwordResetFailed(ResetFailure::UnknownAccount, $email, null, $client);

            throw new ResetRejected;
        }

        $outcome = $this->database->transaction(fn (): Account|ResetFailure => $this->reset($known, $token, $hash, $client));
        if ($outcome instanceof ResetFailure) {
            $this->audit->passwordResetFailed($outcome, $email, $known, $client);

            throw new ResetRejected;
        }
    }

    private function reset(Account $known, string $token, string $hash, ClientContext $client): Account|ResetFailure
    {
        $account = $this->accounts->findForUpdate($known->id);
        if ($account === null || ! $account->canAuthenticate()) {
            $this->tokens->spendDecoyCheck($token);

            return ResetFailure::AccountNotEligible;
        }
        if (! $this->tokens->isValid($account, $token)) {
            return ResetFailure::InvalidToken;
        }

        $this->accounts->save($account->changePassword($hash, DateTimeImmutable::createFromInterface(now())));
        $this->tokens->revoke($account);
        $signedOut = $this->sessions->revokeAllFor($account->id);
        $this->generations->advance($account->id);
        $this->audit->passwordResetCompleted($account, $signedOut, $client);

        return $account;
    }
}
