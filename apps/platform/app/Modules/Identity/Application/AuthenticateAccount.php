<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\PersonRepository;
use App\Modules\Identity\Domain\PlainPassword;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Verifies an email and password and decides whether that Account may sign in. It does
 * NOT create the session: that is the transport's job, and it happens only after this
 * has returned and its transaction has committed.
 *
 * - Lookup is by canonical email only (ADR 0015).
 * - Only an active Account with a credential may authenticate. Invited, disabled, unknown
 *   and wrong-password all return the same undifferentiated failure.
 * - A password check runs on EVERY path, against a throwaway hash when there is no
 *   eligible Account, so response time does not reveal whether the address exists. The password
 *   is normalised by the same PlainPassword boundary as when it was set.
 * - When the Account needs a second factor (ADR 0023) a correct password is NOT a sign-in: no session, no
 *   `last_login_at`, no success event. The result carries a PendingLogin, which CompleteSecondFactor (or the
 *   enrolment use cases) finish from current state.
 * - The success event and the `last_login_at` update share one transaction (ADR 0019).
 *   Failures are not thrown from inside a transaction, so their events are kept.
 * - The plain password is used once and never stored, logged or recorded.
 */
final class AuthenticateAccount
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PersonRepository $people,
        private readonly LoginThrottle $throttle,
        private readonly AuthenticationAudit $audit,
        private readonly PasswordHasher $passwords,
        private readonly ConnectionInterface $database,
        private readonly EffectiveCapabilities $capabilities,
        private readonly SecondFactorRequirement $secondFactor,
        private readonly CredentialMarker $marker,
        private readonly MfaStatuses $mfa,
    ) {}

    public function __invoke(EmailAddress $email, string $password, ClientContext $client): AuthenticationResult
    {
        $block = $this->throttle->block($client->ip, $email);
        if ($block !== null) {
            if ($block->auditable) {
                $this->audit->rateLimited($email, $block, $client);
            }

            return AuthenticationResult::throttled($block->retryAfterSeconds);
        }

        $this->throttle->recordAttempt($client->ip);

        $account = $this->accounts->findByEmail($email);
        $eligible = $account !== null && $account->canAuthenticate() ? $account : null;
        // The password goes through the same normalisation as when it was set (PlainPassword), and a
        // check runs on every path, against a throwaway hash when there is no eligible Account.
        $passwordMatches = $this->passwords->matches(
            PlainPassword::fromInput($password), $eligible->passwordHash ?? $this->passwords->decoyHash(),
        );

        if ($eligible === null || ! $passwordMatches) {
            $this->throttle->recordFailure($email);
            $this->audit->failed($this->reasonFor($account), $email, $account, $client);

            return AuthenticationResult::failed();
        }

        $current = $this->database->transaction(fn (): CurrentAccount|PendingLogin|FailureReason => $this->signIn($eligible, $client));
        if ($current instanceof FailureReason) {
            // The Account changed between the read above and the lock below.
            $this->throttle->recordFailure($email);
            $this->audit->failed($current, $email, $this->accounts->find($eligible->id), $client);

            return AuthenticationResult::failed();
        }
        $this->throttle->clearFailures($email);

        if ($current instanceof PendingLogin) {
            // The password is right, but this Account needs a second factor: NO sign-in is recorded and
            // no session is established. What is returned is the minimum to finish the sign-in.
            return AuthenticationResult::secondFactorPending($current);
        }

        // Read after the sign-in commits, from current state; never stored in the session.
        return AuthenticationResult::authenticated(new CurrentAccount(
            $current->actor, $current->email, $current->displayName, $this->capabilities->for($current->actor),
            $this->mfa->for($current->actor->accountId),
        ));
    }

    /**
     * Records the sign-in, or says why it must not be recorded.
     *
     * The Account was read, and its password verified, BEFORE this transaction opened. Saving that
     * copy back would write its whole state, and if a disable committed in between it would undo
     * it: a disabled Account silently re-enabled by someone signing in. So it is re-read here WITH a
     * lock, and re-checked, and the copy that is saved is the one just read.
     *
     * The same window applies to the credential. A password verified against the hash read earlier
     * proves nothing about the hash now stored: if a reset or change committed in between, the
     * caller proved a password that has since been replaced, and must not be signed in with it.
     */
    private function signIn(Account $verified, ClientContext $client): CurrentAccount|PendingLogin|FailureReason
    {
        $account = $this->accounts->findForUpdate($verified->id);
        if ($account === null || ! $account->canAuthenticate()) {
            return FailureReason::AccountNotActive;
        }
        if ($account->passwordHash !== $verified->passwordHash) {
            return FailureReason::WrongPassword;
        }

        // A second factor is due (the policy is Access's, through a port; an authenticator that exists is
        // always used). Then this is not a sign-in yet: it becomes a pending one, bound to the credential
        // that was just verified, and nothing is recorded until the second factor completes it.
        $need = $this->secondFactor->for($account);
        if ($need !== SecondFactorNeed::None) {
            return new PendingLogin($account->id, $this->marker->for($account), $need);
        }

        $person = $this->people->find($account->personId)
            ?? throw new LogicException('An account exists without its person.');
        $actor = Actor::user($account->id, $account->personId);

        $this->accounts->save($account->recordLogin(DateTimeImmutable::createFromInterface(now())));
        $this->audit->succeeded($actor, $client);

        return new CurrentAccount($actor, $account->email->value, $person->displayName);
    }

    private function reasonFor(?Account $account): FailureReason
    {
        return match (true) {
            $account === null => FailureReason::UnknownAccount,
            ! $account->canAuthenticate() => FailureReason::AccountNotActive,
            default => FailureReason::WrongPassword,
        };
    }
}
