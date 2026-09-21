<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;

/**
 * Recovers an Account whose person has lost their authenticator AND every recovery code (ADR 0024): it removes
 * the second factor so the person can enrol a new one at their next sign-in. Two callers, with different roots
 * of trust, and two methods so neither can be mistaken for the other:
 *
 * - `byAdministrator`: another operator, who Access has already authorized and who proved themselves recently.
 *   The target must be ANOTHER Account (SelfMfaResetProhibited), enforced here so no adapter can forget.
 * - `fromServer`: the operator's console command, whose authority is server access (ADR 0020). No Actor.
 *
 * What it does, in ONE transaction that locks and re-reads the Account first (every operation that checks a
 * second factor locks the same row, so whatever was waiting on it then decides on what this committed):
 *
 * - removes the authenticator, active or pending, and every recovery code;
 * - ends every session of the Account and advances its security generation (ADR 0025), so a challenge that
 *   committed a moment before this cannot establish a usable session afterwards either. A half-finished sign-in
 *   is not attributable to an Account (it has no `user_id`), so it is not deleted; it is DEFEATED, because
 *   finishing it needs a live factor and there is none;
 * - records `mfa.administratively_reset` or `mfa.reset_from_server`, with counts and no secret of any kind.
 *
 * What it does NOT do: change the password, the status, the roles or the Person; generate a secret; show a code.
 * The Account can prove its password, and the next sign-in of one that has Console access goes to enrolment
 * (ADR 0023). It is not a way to remove administrator authority, so the last-administrator invariant is untouched.
 *
 * **This use case does not authorize its caller** (Identity cannot ask Access); the adapter must, first.
 */
final readonly class ResetMultiFactor
{
    public function __construct(
        private AccountRepository $accounts,
        private TotpFactorRepository $factors,
        private RecoveryCodeRepository $recoveryCodes,
        private AccountSessions $sessions,
        private AccountSecurityGeneration $generations,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccountNotFound
     * @throws SelfMfaResetProhibited the target is the acting Account
     */
    public function byAdministrator(Actor $by, AccountId $target): MfaReset
    {
        if ($by->accountId->equals($target)) {
            throw new SelfMfaResetProhibited;
        }

        return $this->reset($target, $by, IdentityEvent::MfaAdministrativelyReset);
    }

    /**
     * @throws AccountNotFound
     */
    public function fromServer(AccountId $target): MfaReset
    {
        return $this->reset($target, null, IdentityEvent::MfaResetFromServer);
    }

    private function reset(AccountId $target, ?Actor $by, IdentityEvent $event): MfaReset
    {
        return $this->database->transaction(function () use ($target, $by, $event): MfaReset {
            $account = $this->accounts->findForUpdate($target) ?? throw new AccountNotFound;

            $hadAuthenticator = $this->factors->findByAccount($target)?->isActive() === true;
            $removedFactor = $this->factors->delete($target);
            $removedCodes = $this->recoveryCodes->deleteAll($target);
            if (! $removedFactor && $removedCodes === 0) {
                return new MfaReset(false);
            }

            $signedOut = $this->sessions->revokeAllFor($target);
            $this->generations->advance($target);

            ($this->record)(
                $event->value, SecurityEventOutcome::Success,
                $by, $account->personId, $account->id, null, null,
                ['had_authenticator' => $hadAuthenticator, 'recovery_codes_removed' => $removedCodes, 'signed_out' => $signedOut],
            );

            return new MfaReset(true, $signedOut);
        }, 3);
    }
}
