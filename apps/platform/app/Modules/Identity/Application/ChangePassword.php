<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PlainPassword;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * An authenticated person changes their own password.
 *
 * A session alone is not enough. Until step-up authentication arrives with MFA, **re-proving the
 * current password is the re-authentication control**: the caller must present it, and it is
 * verified against the Account's stored credential as it is at the moment of the change.
 *
 * - **Before the transaction:** rate limit (per source address and per Account, so a stolen session
 *   cannot be used to guess the current password), then everything that needs the network or is
 *   slow: the password policy for the NEW password (including the breach check) and hashing it.
 * - **In the transaction, and first:** lock and re-read the Account. It must still be able to sign in
 *   (a disable that committed first is seen: the request is over), and the current password is
 *   verified against the LOCKED hash, so a reset or change that committed a moment ago is honoured
 *   rather than the credential the caller's session was created with. Then set the new password, end
 *   the Account's OTHER sessions, and record `password.changed`, atomically. The caller's own session
 *   is spared here and rotated by the transport (a new id and CSRF token, and a new authentication
 *   instant, since the current password was just proved).
 * - The plain passwords are used once and never stored, logged or recorded; the session id is opaque
 *   to this class and is not recorded either.
 *
 * A wrong current password changes nothing and does not touch the session. It is not audited (the
 * frozen event catalog has no event for it); repeated wrong attempts engage the rate limit, which is.
 */
final readonly class ChangePassword
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountSessions $sessions,
        private PasswordPolicy $policy,
        private PasswordHasher $passwords,
        private AttemptThrottle $throttle,
        private CredentialAudit $audit,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  string  $keepSessionId  the caller's own session, which is kept (and rotated by the transport)
     *
     * @throws TooManyAttempts the caller's rate limit is engaged
     * @throws PasswordRejected the new password does not meet the policy
     * @throws CompromisedPasswordCheckUnavailable the breach check could not be completed; retry
     * @throws NoLongerAuthenticated the Account can no longer sign in
     * @throws CurrentPasswordIncorrect the current password was wrong
     */
    public function __invoke(
        Actor $actor,
        #[\SensitiveParameter] string $currentPassword,
        #[\SensitiveParameter] string $newPassword,
        #[\SensitiveParameter] string $keepSessionId,
        ClientContext $client,
    ): void {
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

        $this->database->transaction(fn () => $this->change($actor, $current, $hash, $keepSessionId, $client));
    }

    /**
     * Nothing has been written when the two refusals are thrown, so leaving the transaction by
     * exception undoes nothing and no failure event is at risk of being rolled back with it.
     */
    private function change(Actor $actor, PlainPassword $current, string $hash, string $keepSessionId, ClientContext $client): void
    {
        $account = $this->accounts->findForUpdate($actor->accountId);
        if ($account === null || ! $this->stillSignedIn($account, $actor)) {
            throw new NoLongerAuthenticated;
        }
        // The stored hash is non-null here: an Account that can authenticate has a credential.
        if (! $this->passwords->matches($current, (string) $account->passwordHash)) {
            throw new CurrentPasswordIncorrect;
        }

        $this->accounts->save($account->changePassword($hash, DateTimeImmutable::createFromInterface(now())));
        $signedOut = $this->sessions->revokeAllExcept($account->id, $keepSessionId);
        $this->audit->passwordChanged($actor, $signedOut, $client);
    }

    /** Still able to sign in, and still the Person the request was authenticated as. */
    private function stillSignedIn(Account $account, Actor $actor): bool
    {
        return $account->canAuthenticate() && $account->personId->equals($actor->personId);
    }
}
