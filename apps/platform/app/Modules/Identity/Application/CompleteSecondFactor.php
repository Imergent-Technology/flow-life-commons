<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\Actor;
use App\Shared\Domain\AuthenticationMethod;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Finishes a sign-in whose password was already proved: the person presents a code from their
 * authenticator, or one recovery code. Success is the only thing that lets the transport establish the
 * authenticated session; it does that after this has returned and its transaction has committed.
 *
 * Everything is decided from CURRENT state, inside one transaction, and first. The Account is locked and
 * re-read, and must still be able to sign in (so an Account disabled after its password was accepted
 * cannot finish), and the digest of the password that was proved must still match the stored credential
 * (so a password replaced in the meantime, by a reset or a change, ends the pending sign-in instead of
 * letting it finish on a proof that no longer exists). Only then is the code checked: an authenticator
 * code at most once per time step, a recovery code by one atomic update, so of two simultaneous uses of
 * one code exactly one succeeds. The success event, `last_login_at` and any spent code commit together.
 *
 * A refusal is RETURNED, not thrown, so its event commits (a throw would roll it back). Only a wrong code
 * is told to the caller as one; every other reason reads as "start again".
 */
final readonly class CompleteSecondFactor
{
    public function __construct(
        private AccountRepository $accounts,
        private PersonRepository $people,
        private SecondFactorVerifier $verifier,
        private CredentialMarker $marker,
        private AttemptThrottle $throttle,
        private AuthenticationAudit $authenticationAudit,
        private CredentialAudit $credentialAudit,
        private MfaAudit $audit,
        private MfaStatuses $statuses,
        private EffectiveCapabilities $capabilities,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws TooManyAttempts
     */
    public function __invoke(PendingLogin $pending, SecondFactorProof $proof, ClientContext $client): SecondFactorOutcome
    {
        $identifier = $pending->accountId->value;
        $block = $this->throttle->block(ThrottledAction::MfaChallenge, $client->ip, $identifier);
        if ($block !== null) {
            if ($block->auditable) {
                $this->credentialAudit->rateLimited(ThrottledAction::MfaChallenge, $block, null, $client);
            }
            throw new TooManyAttempts($block->retryAfterSeconds);
        }
        $this->throttle->record(ThrottledAction::MfaChallenge, $client->ip, $identifier);

        $result = $this->database->transaction(fn (): array|SecondFactorFailure => $this->complete($pending, $proof, $client));
        if ($result instanceof SecondFactorFailure) {
            return SecondFactorOutcome::refused($result);
        }

        [$actor, $email, $displayName, $method] = $result;

        // Read after the sign-in commits, from current state; never stored in the session.
        return SecondFactorOutcome::signedIn(
            new CurrentAccount($actor, $email, $displayName, $this->capabilities->for($actor), $this->statuses->for($actor->accountId)),
            $method,
        );
    }

    /** @return array{Actor, string, string, SecondFactorMethod}|SecondFactorFailure */
    private function complete(PendingLogin $pending, SecondFactorProof $proof, ClientContext $client): array|SecondFactorFailure
    {
        $now = DateTimeImmutable::createFromInterface(now());

        $account = $this->accounts->findForUpdate($pending->accountId);
        $refusal = $this->refusal($account, $pending);
        if ($refusal !== null) {
            $this->audit->challengeFailed($account, $refusal, 'sign_in', $client);

            return $refusal;
        }
        assert($account instanceof Account);

        $verified = $this->verifier->verify($account, $proof, $now);
        if ($verified === null) {
            $this->audit->challengeFailed($account, SecondFactorFailure::InvalidCode, 'sign_in', $client);

            return SecondFactorFailure::InvalidCode;
        }

        $person = $this->people->find($account->personId)
            ?? throw new LogicException('An account exists without its person.');
        $actor = Actor::user($account->id, $account->personId, AuthenticationMethod::SessionWithSecondFactor);

        $this->accounts->save($account->recordLogin($now));
        if ($verified->method === SecondFactorMethod::RecoveryCode) {
            $this->audit->recoveryCodeUsed($account, $verified->recoveryCodesRemaining ?? 0, 'sign_in', $client);
        }
        $this->authenticationAudit->succeeded($actor, $client, $verified->method);

        return [$actor, $account->email->value, $person->displayName, $verified->method];
    }

    private function refusal(?Account $account, PendingLogin $pending): ?SecondFactorFailure
    {
        return match (true) {
            $account === null, ! $account->canAuthenticate() => SecondFactorFailure::AccountNotActive,
            ! hash_equals($pending->credentialMarker, $this->marker->for($account)) => SecondFactorFailure::CredentialChanged,
            $pending->need !== SecondFactorNeed::Challenge => SecondFactorFailure::WrongStep,
            default => null,
        };
    }
}
