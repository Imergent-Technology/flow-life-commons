<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\InvitationToken;
use App\Modules\Identity\Domain\PlainPassword;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Finishes setting up an invited Account: the holder of a valid invitation token chooses a password
 * and the Account becomes `active`. It does NOT sign them in; they use the ordinary login.
 *
 * - **The token** is presented by the caller, hashed before it is looked up, and never stored,
 *   logged or recorded. There is one outcome for every way it can be unusable (InvitationRejected).
 * - **No network call inside the transaction.** Everything that needs one (the breached-password
 *   check) and everything slow (hashing) is done first, so the transaction only reads, checks and
 *   writes. The password is judged BEFORE the token is looked up, so a weak password gets the same
 *   answer whatever the token is: rejecting a password reveals nothing about a token.
 * - **The transaction** re-reads everything under lock and re-checks it (the Phase 4 rule: never save
 *   an Account read before a race window). The invitation is locked first, which is what makes it
 *   one-time under concurrent acceptance: the second caller waits, then sees `accepted_at` set. The
 *   Account is then locked and must STILL be `invited`, so acceptance can never resurrect a disabled
 *   Account. Password, activation, `accepted_at` and the security event commit together or not at all.
 *
 * **Whether it verifies the email is the invitation's, not the caller's.** `email_verified_at` means the platform has
 * evidence the holder controls the mailbox. An invitation the platform MAILED to the address is that evidence, so
 * accepting one sets it, in the same save as the activation. One handed over by an operator (the administrator
 * bootstrap) is not, so accepting it leaves the email unverified (ADR 0024). The event records the channel.
 */
final readonly class AcceptInvitation
{
    public function __construct(
        private AccountInvitationRepository $invitations,
        private AccountRepository $accounts,
        private PasswordPolicy $policy,
        private PasswordHasher $passwords,
        private AttemptThrottle $throttle,
        private CredentialAudit $audit,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws TooManyAttempts the caller's rate limit is engaged
     * @throws PasswordRejected the password does not meet the policy
     * @throws CompromisedPasswordCheckUnavailable the breach check could not be completed; retry
     * @throws InvitationRejected the invitation cannot be accepted, for any reason
     */
    public function __invoke(string $presentedToken, #[\SensitiveParameter] string $password, ClientContext $client): void
    {
        $block = $this->throttle->block(ThrottledAction::InvitationAcceptance, $client->ip, null);
        if ($block !== null) {
            if ($block->auditable) {
                $this->audit->rateLimited(ThrottledAction::InvitationAcceptance, $block, null, $client);
            }

            throw new TooManyAttempts($block->retryAfterSeconds);
        }
        $this->throttle->record(ThrottledAction::InvitationAcceptance, $client->ip, null);

        try {
            $token = InvitationToken::fromPresented($presentedToken);
        } catch (InvalidArgumentException) {
            throw new InvitationRejected;
        }

        $plain = PlainPassword::fromInput($password);
        $this->policy->assertAcceptable($plain);
        $hash = $this->passwords->hash($plain);

        $accepted = $this->database->transaction(fn (): bool => $this->accept($token, $hash, $client));
        if (! $accepted) {
            throw new InvitationRejected;
        }
    }

    private function accept(InvitationToken $token, string $hash, ClientContext $client): bool
    {
        $now = DateTimeImmutable::createFromInterface(now());

        $invitation = $this->invitations->findByTokenForUpdate($token);
        if ($invitation === null || ! $invitation->isUsableAt($now)) {
            return false;
        }

        $account = $this->accounts->findForUpdate($invitation->accountId);
        if ($account === null || $account->status !== AccountStatus::Invited) {
            return false;
        }

        $activated = $account->activate($hash, $now);
        $this->accounts->save($invitation->channel->provesMailbox() ? $activated->verifyEmail($now) : $activated);
        $this->invitations->save($invitation->accept($now));
        $this->audit->invitationAccepted($account, $invitation->invitedByAccountId === null, $invitation->channel, $client);

        return true;
    }
}
