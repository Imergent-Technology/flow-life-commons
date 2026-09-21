<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Takes an Account out of service: it can no longer authenticate, and none of its sessions
 * can be used again. The Person, their role assignments and all their history are untouched:
 * losing a login must not destroy organisational history (ADR 0015). Re-enabling is not part
 * of this phase.
 *
 * One transaction does all of it, so it commits or rolls back as a unit:
 *
 * 1. **Every registered deactivation guard runs first** (AccountDeactivationGuard). Access's
 *    refuses to remove the last active administrator. Guards run before this transaction
 *    touches anything, so the locks they take precede the locks below on every path; that
 *    fixed order is what keeps concurrent removals from deadlocking.
 * 2. The Account is re-read WITH a lock, and re-checked: what the guards decided is only
 *    valid for the state they saw.
 * 3. It is disabled and saved, every session of the Account is deleted, the Account's security
 *    generation is advanced (ADR 0025), and `account.disabled` is recorded (ADR 0019). If the audit
 *    write fails, all of it rolls back.
 *
 * **This use case does not authorize its caller.** Identity cannot ask Access what a caller
 * may do (that dependency runs the other way), so whichever adapter exposes this must require a
 * capability defined by Access first. `$by` is recorded for the audit trail only; null means
 * the platform or an operator with server access. No adapter exists yet.
 */
final readonly class DisableAccount
{
    /**
     * @param  iterable<AccountDeactivationGuard>  $guards
     */
    public function __construct(
        private AccountRepository $accounts,
        private AccountSessions $sessions,
        private AccountSecurityGeneration $generations,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
        private iterable $guards,
    ) {}

    /**
     * @throws AccountNotFound
     * @throws AccountDeactivationRefused a guard vetoed it
     */
    public function __invoke(AccountId $accountId, ?Actor $by = null): DeactivationOutcome
    {
        // Read BEFORE the transaction opens: the person never changes, and a read inside it
        // would fix the snapshot the guards' locking reads are there to get past.
        $account = $this->accounts->find($accountId) ?? throw new AccountNotFound;
        if ($account->status === AccountStatus::Disabled) {
            return DeactivationOutcome::AlreadyDisabled;
        }

        return $this->database->transaction(function () use ($accountId, $account, $by): DeactivationOutcome {
            foreach ($this->guards as $guard) {
                $guard->assertMayDeactivate($account->id, $account->personId);
            }

            $current = $this->accounts->findForUpdate($accountId) ?? throw new AccountNotFound;
            if ($current->status === AccountStatus::Disabled) {
                return DeactivationOutcome::AlreadyDisabled;
            }

            $this->accounts->save($current->disable(DateTimeImmutable::createFromInterface(now())));
            $signedOut = $this->sessions->revokeAllFor($accountId);
            $this->generations->advance($accountId);

            ($this->record)(
                IdentityEvent::AccountDisabled->value, SecurityEventOutcome::Success,
                $by, $current->personId, $current->id, null, null,
                ['previous_status' => $current->status->value, 'signed_out' => $signedOut],
            );

            return DeactivationOutcome::Disabled;
        }, 3);
    }
}
