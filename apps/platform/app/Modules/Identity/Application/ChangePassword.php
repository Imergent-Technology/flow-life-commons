<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PlainPassword;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * An authenticated person changes their own password.
 *
 * A session alone is not enough: the caller re-proves the current password, and, **if the Account has an
 * active authenticator, a fresh second factor too** (an authenticator code or an unused recovery code; ADR
 * 0023, G6 WP1-01). An Account with no authenticator changes its password on the current password alone.
 * Whether the Account has one is read from the Account itself, under its row lock, in the same transaction
 * as the change: nothing the client says about enrolment is believed, and an enrolment, replacement or reset
 * that committed a moment ago is honoured. The factor is checked by the one place that checks them
 * (SecondFactorVerifier, through SecurityProof), so a code is accepted once and a recovery code is spent once.
 *
 * - **Before the transaction:** rate limit (per source address and per Account, so a stolen session cannot
 *   be used to guess the current password or a code; this one allowance covers both, each attempt counting
 *   once), then everything that needs the network or is slow: the password policy for the NEW password
 *   (including the breach check) and hashing it.
 * - **In the transaction, and first:** lock and re-read the Account. It must still be able to sign in
 *   (a disable that committed first is seen: the request is over), and the current password is
 *   verified against the LOCKED hash, so a reset or change that committed a moment ago is honoured
 *   rather than the credential the caller's session was created with. Then, if the Account has an active
 *   authenticator, the second factor. Then set the new password, end the Account's OTHER sessions, advance
 *   its security generation (ADR 0025), and record `password.changed`, atomically. The caller's own session is
 *   spared here and rotated by the transport (a new id and CSRF token, and the new generation bound to it;
 *   its authentication instant is NOT restarted, because a password change is not a sign-in), which is why
 *   the generation is returned.
 * - Everything that can refuse does so before anything is written, and a spent code is written inside the same
 *   transaction as the change, so a refusal or a failure anywhere (a wrong password, a wrong or replayed code,
 *   a failed save or audit write) leaves the password, the sessions, the generation and the factor untouched.
 * - The plain passwords are used once and never stored, logged or recorded; the session id is opaque
 *   to this class and is not recorded either.
 *
 * A wrong current password changes nothing and does not touch the session. It is not audited (the
 * frozen event catalog has no event for it); repeated wrong attempts engage the rate limit, which is. A
 * refused or missing second factor is recorded as `mfa.challenge_failed` (during `password_change`), after the
 * rollback, where it cannot be rolled back with it.
 */
final readonly class ChangePassword
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountSessions $sessions,
        private AccountSecurityGeneration $generations,
        private PasswordPolicy $policy,
        private PasswordHasher $passwords,
        private AttemptThrottle $throttle,
        private CredentialAudit $audit,
        private SecurityProof $proofs,
        private TotpFactorRepository $factors,
        private MfaAudit $mfaAudit,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  string  $keepSessionId  the caller's own session, which is kept (and rotated by the transport)
     * @return int the Account's new security generation (ADR 0025), which the transport binds the
     *             caller's own session to. It is the value THIS transaction committed, not one read
     *             afterwards: anything that supersedes it a moment later must still end this session.
     *
     * @throws TooManyAttempts the caller's rate limit is engaged
     * @throws PasswordRejected the new password does not meet the policy
     * @throws CompromisedPasswordCheckUnavailable the breach check could not be completed; retry
     * @throws NoLongerAuthenticated the Account can no longer sign in
     * @throws CurrentPasswordIncorrect the current password was wrong
     * @throws SecondFactorRejected the Account has an authenticator and `$proof` is missing, wrong or already used
     */
    public function __invoke(
        Actor $actor,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
        #[\SensitiveParameter] string $keepSessionId,
        ClientContext $client,
        ?SecondFactorProof $proof = null,
    ): int {
        $identifier = $actor->accountId->value;
        $block = $this->throttle->block(ThrottledAction::PasswordChange, $client->ip, $identifier);
        if ($block !== null) {
            if ($block->auditable) {
                $this->audit->rateLimited(ThrottledAction::PasswordChange, $block, null, $client);
            }

            throw new TooManyAttempts($block->retryAfterSeconds);
        }
        $this->throttle->record(ThrottledAction::PasswordChange, $client->ip, $identifier);

        $new = PlainPassword::fromInput($newPassword);
        $this->policy->assertAcceptable($new);
        $hash = $this->passwords->hash($new);
        $current = PlainPassword::fromInput($currentPassword);

        try {
            return $this->database->transaction(fn (): int => $this->change($actor, $current, $hash, $keepSessionId, $client, $proof));
        } catch (SecondFactorRejected $e) {
            $this->mfaAudit->challengeFailed($e->account, $e->reason, 'password_change', $client);

            throw $e;
        }
    }

    /**
     * Nothing has been written when the refusals are thrown (the wrong password, a missing or wrong factor), so
     * leaving the transaction by exception undoes nothing and no failure event is at risk of being rolled back
     * with it. A recovery code or authenticator step spent as the proof IS a write, and rolls back if anything
     * after it fails.
     */
    private function change(Actor $actor, PlainPassword $current, string $hash, string $keepSessionId, ClientContext $client, ?SecondFactorProof $proof): int
    {
        $account = $this->accounts->findForUpdate($actor->accountId);
        if ($account === null || ! $this->stillSignedIn($account, $actor)) {
            throw new NoLongerAuthenticated;
        }
        // The stored hash is non-null here: an Account that can authenticate has a credential.
        if (! $this->passwords->matches($current, (string) $account->passwordHash)) {
            throw new CurrentPasswordIncorrect;
        }
        // Enrolment is the Account's, read under the lock; the request's shape never decides it.
        if ($this->factors->findByAccount($account->id)?->isActive() === true) {
            $now = DateTimeImmutable::createFromInterface(now());
            $this->proofs->confirmSecondFactor(
                $account,
                $proof ?? throw new SecondFactorRejected(SecondFactorFailure::CodeRequired, $account),
                $now, $client, 'password_change',
            );
        }

        $this->accounts->save($account->changePassword($hash, DateTimeImmutable::createFromInterface(now())));
        $signedOut = $this->sessions->revokeAllExcept($account->id, $keepSessionId);
        $generation = $this->generations->advance($account->id);
        $this->audit->passwordChanged($actor, $signedOut, $client);

        return $generation;
    }

    /** Still able to sign in, and still the Person the request was authenticated as. */
    private function stillSignedIn(Account $account, Actor $actor): bool
    {
        return $account->canAuthenticate() && $account->personId->equals($actor->personId);
    }
}
