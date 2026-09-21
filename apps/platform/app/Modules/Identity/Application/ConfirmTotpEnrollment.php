<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PersonRepository;
use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Shared\Domain\Actor;
use App\Shared\Domain\AuthenticationMethod;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * The last step of a first enrolment, and of the sign-in it belongs to: the person proves they hold the
 * pending secret by entering a valid CURRENT code from their app.
 *
 * Only that proof makes the authenticator real. Generating a secret enrolls nothing; a wrong code leaves
 * MFA disabled and the pending secret in place to try again. On a right one, in ONE transaction: the
 * pending secret becomes the active one, a fresh set of recovery codes is stored (as digests) and
 * returned to be shown once, `mfa.enabled` and `authentication.succeeded` are recorded, and the sign-in
 * completes. As in CompleteSecondFactor, the Account is locked and re-read first and the password digest
 * re-checked, so a disable or a password replaced in the meantime ends it.
 */
final readonly class ConfirmTotpEnrollment
{
    public function __construct(
        private AccountRepository $accounts,
        private PersonRepository $people,
        private TotpFactorRepository $factors,
        private SecondFactorVerifier $verifier,
        private IssueRecoveryCodes $recoveryCodes,
        private CredentialMarker $marker,
        private AttemptThrottle $throttle,
        private AuthenticationAudit $authenticationAudit,
        private CredentialAudit $credentialAudit,
        private MfaAudit $audit,
        private MfaStatuses $statuses,
        private EffectiveCapabilities $capabilities,
        private AccountSecurityGeneration $generations,
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

        $result = $this->database->transaction(fn (): array|SecondFactorFailure => $this->confirm($pending, $proof, $client));
        if ($result instanceof SecondFactorFailure) {
            return SecondFactorOutcome::refused($result);
        }

        [$actor, $email, $displayName, $codes, $securityGeneration] = $result;

        // Read after the sign-in commits, from current state; never stored in the session.
        return SecondFactorOutcome::enrolled(
            new CurrentAccount($actor, $email, $displayName, $this->capabilities->for($actor), $this->statuses->for($actor->accountId)),
            $codes,
            $securityGeneration,
        );
    }

    /** @return array{Actor, string, string, list<string>, int}|SecondFactorFailure */
    private function confirm(PendingLogin $pending, SecondFactorProof $proof, ClientContext $client): array|SecondFactorFailure
    {
        $now = DateTimeImmutable::createFromInterface(now());

        $account = $this->accounts->findForUpdate($pending->accountId);
        $factor = $account === null ? null : $this->factors->findByAccount($account->id);
        $refusal = $this->refusal($account, $factor, $pending, $now);
        if ($refusal !== null) {
            $this->audit->challengeFailed($account, $refusal, 'sign_in', $client);

            return $refusal;
        }
        assert($account instanceof Account && $factor instanceof TotpFactor);

        $step = $this->verifier->matchPending($factor, $proof, $now);
        if ($step === null) {
            $this->audit->challengeFailed($account, SecondFactorFailure::InvalidCode, 'sign_in', $client);

            return SecondFactorFailure::InvalidCode;
        }

        $person = $this->people->find($account->personId)
            ?? throw new LogicException('An account exists without its person.');

        $this->factors->save($factor->confirmPending($step, $now));
        $codes = ($this->recoveryCodes)($account->id, $now);
        $this->accounts->save($account->recordLogin($now));

        $actor = Actor::user($account->id, $account->personId, AuthenticationMethod::SessionWithSecondFactor);
        $this->audit->enabled($account, $actor, $client);
        $this->authenticationAudit->succeeded($actor, $client, SecondFactorMethod::Enrollment);

        // Under the lock this transaction holds: the generation this proof was checked against (ADR 0025).
        return [
            $actor, $account->email->value, $person->displayName, $codes,
            $this->generations->current($account->id) ?? throw new LogicException('An account signed in without a security generation.'),
        ];
    }

    private function refusal(?Account $account, ?TotpFactor $factor, PendingLogin $pending, DateTimeImmutable $now): ?SecondFactorFailure
    {
        return match (true) {
            $account === null, ! $account->canAuthenticate() => SecondFactorFailure::AccountNotActive,
            ! hash_equals($pending->credentialMarker, $this->marker->for($account)) => SecondFactorFailure::CredentialChanged,
            $pending->need !== SecondFactorNeed::Enrollment => SecondFactorFailure::WrongStep,
            $factor === null || ! $factor->hasFreshPending($now, TotpFactor::PENDING_LIFETIME_SECONDS) => SecondFactorFailure::NoPendingSecret,
            $factor->isActive() => SecondFactorFailure::AlreadyEnrolled,
            default => null,
        };
    }
}
