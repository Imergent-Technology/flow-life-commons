<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PlainPassword;
use App\Shared\Domain\Actor;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * "Prove it is really you, now": the current password AND a second factor, for an already-signed-in
 * Account, as the gate in front of every operation that changes or exposes a credential (replacing the
 * authenticator, regenerating recovery codes) and of step-up verification. A session alone is never
 * enough.
 *
 * `run` does, in order:
 *
 * 1. Rate limit (per source address and per Account, so a stolen session cannot be used to guess the
 *    password or a code).
 * 2. In ONE transaction, and first: lock and re-read the Account (the Phase 4 and 5 rule: what the
 *    session was created with proves nothing about now), require it can still sign in, verify the
 *    password against the LOCKED hash, then the second factor. A wrong password or code changes nothing.
 * 3. Only then run `$work`, still inside that transaction, with the Account and how the factor was met.
 *
 * A refusal is thrown from inside the transaction before anything is written, and its failure event is
 * recorded here, after the rollback, where it cannot be rolled back with it. A recovery code spent as
 * the proof is recorded as spent.
 */
final readonly class SecurityProof
{
    public function __construct(
        private AccountRepository $accounts,
        private SecondFactorVerifier $verifier,
        private PasswordHasher $passwords,
        private AttemptThrottle $throttle,
        private CredentialAudit $credentialAudit,
        private MfaAudit $audit,
        private ConnectionInterface $database,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(Account, VerifiedSecondFactor, DateTimeImmutable): T  $work
     * @return T
     *
     * @throws TooManyAttempts
     * @throws NoLongerAuthenticated
     * @throws CurrentPasswordIncorrect
     * @throws SecondFactorRejected
     */
    public function run(
        Actor $actor,
        #[\SensitiveParameter] string $currentPassword,
        SecondFactorProof $proof,
        ClientContext $client,
        Closure $work,
    ): mixed {
        $identifier = $actor->accountId->value;
        $block = $this->throttle->block(ThrottledAction::SecurityVerification, $client->ip, $identifier);
        if ($block !== null) {
            if ($block->auditable) {
                $this->credentialAudit->rateLimited(ThrottledAction::SecurityVerification, $block, null, $client);
            }
            throw new TooManyAttempts($block->retryAfterSeconds);
        }
        $this->throttle->record(ThrottledAction::SecurityVerification, $client->ip, $identifier);

        $password = PlainPassword::fromInput($currentPassword);

        try {
            return $this->database->transaction(function () use ($actor, $password, $proof, $client, $work): mixed {
                $now = DateTimeImmutable::createFromInterface(now());
                $account = $this->accounts->findForUpdate($actor->accountId);
                if ($account === null || ! $account->canAuthenticate() || ! $account->personId->equals($actor->personId)) {
                    throw new NoLongerAuthenticated;
                }
                // Non-null here: an Account that can authenticate has a credential.
                if (! $this->passwords->matches($password, (string) $account->passwordHash)) {
                    throw new CurrentPasswordIncorrect;
                }

                $verified = $this->verifier->verify($account, $proof, $now)
                    ?? throw new SecondFactorRejected(SecondFactorFailure::InvalidCode, $account, $proof->method);
                if ($verified->method === SecondFactorMethod::RecoveryCode) {
                    $this->audit->recoveryCodeUsed($account, $verified->recoveryCodesRemaining ?? 0, 'security_verification', $client);
                }

                return $work($account, $verified, $now);
            });
        } catch (SecondFactorRejected $e) {
            $this->audit->challengeFailed($e->account, $e->reason, 'security_verification', $client);

            throw $e;
        }
    }
}
