<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * The person proves they hold the NEW authenticator, and only then does it replace the old one.
 *
 * Until this succeeds nothing has changed. On success, in one transaction: the pending secret becomes the
 * active one (the old authenticator stops working from this instant), the Account's OTHER sessions end (a
 * credential was replaced), its security generation is advanced (ADR 0025, so a sign-in finishing on the
 * old authenticator cannot establish a usable session after this), and `mfa.replaced` is recorded. The
 * caller's own session is kept, rotated by the transport and re-bound to the new generation, which is why
 * that generation is returned. Recovery codes are untouched.
 *
 * No password is asked here: the fresh proof was given when the replacement began, and the pending secret
 * can be confirmed only with a code from the secret that was generated then, which only that person saw.
 */
final readonly class ConfirmAuthenticatorReplacement
{
    public function __construct(
        private AccountRepository $accounts,
        private TotpFactorRepository $factors,
        private SecondFactorVerifier $verifier,
        private AccountSessions $sessions,
        private AccountSecurityGeneration $generations,
        private AttemptThrottle $throttle,
        private CredentialAudit $credentialAudit,
        private MfaAudit $audit,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  string  $keepSessionId  the caller's own session, which is kept (and rotated by the transport)
     * @return int the Account's new security generation (ADR 0025), which the transport binds the
     *             caller's own session to
     *
     * @throws TooManyAttempts
     * @throws NoLongerAuthenticated
     * @throws SecondFactorRejected
     */
    public function __invoke(Actor $actor, SecondFactorProof $proof, #[\SensitiveParameter] string $keepSessionId, ClientContext $client): int
    {
        $identifier = $actor->accountId->value;
        $block = $this->throttle->block(ThrottledAction::MfaChallenge, $client->ip, $identifier);
        if ($block !== null) {
            if ($block->auditable) {
                $this->credentialAudit->rateLimited(ThrottledAction::MfaChallenge, $block, null, $client);
            }
            throw new TooManyAttempts($block->retryAfterSeconds);
        }
        $this->throttle->record(ThrottledAction::MfaChallenge, $client->ip, $identifier);

        try {
            return $this->database->transaction(fn (): int => $this->confirm($actor, $proof, $keepSessionId, $client));
        } catch (SecondFactorRejected $e) {
            $this->audit->challengeFailed($e->account, $e->reason, 'security_verification', $client);

            throw $e;
        }
    }

    private function confirm(Actor $actor, SecondFactorProof $proof, string $keepSessionId, ClientContext $client): int
    {
        $now = DateTimeImmutable::createFromInterface(now());
        $account = $this->accounts->findForUpdate($actor->accountId);
        if ($account === null || ! $account->canAuthenticate() || ! $account->personId->equals($actor->personId)) {
            throw new NoLongerAuthenticated;
        }

        $factor = $this->factors->findByAccount($account->id);
        if ($factor === null || ! $factor->isActive() || ! $factor->hasFreshPending($now, TotpFactor::PENDING_LIFETIME_SECONDS)) {
            throw new SecondFactorRejected(SecondFactorFailure::NoPendingSecret, $account, $proof->method);
        }
        $step = $this->verifier->matchPending($factor, $proof, $now)
            ?? throw new SecondFactorRejected(SecondFactorFailure::InvalidCode, $account, $proof->method);

        $this->factors->save($factor->confirmPending($step, $now));
        $signedOut = $this->sessions->revokeAllExcept($account->id, $keepSessionId);
        $generation = $this->generations->advance($account->id);
        $this->audit->replaced($actor, $signedOut, $client);

        return $generation;
    }
}
