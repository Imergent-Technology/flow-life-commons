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
 * Puts a DISABLED Account back into service: the reverse of DisableAccount, and no more than that.
 *
 * - **It only reopens the door.** The Account returns to the status it had before it was disabled (active if it
 *   ever chose a password, invited if it never did). It creates NO session, sets no password, bypasses no second
 *   factor and changes no role: the Person, their assignments, their credential and their history are untouched.
 *   Whether the person can then get in is the ordinary sign-in's question, second factor included (ADR 0023).
 * - **Decided on current state.** The Account is locked and re-read INSIDE the transaction, and only a disabled
 *   one is enabled: two operators racing (or a disable racing an enable) each decide on what the other committed,
 *   never on what they read earlier. An Account that is not disabled is refused (`NotDisabled`), so an operator is
 *   told what really happened.
 * - No guard chain: enabling can only add authority back, never remove any, so there is nothing to protect.
 * - The change and `account.reenabled` commit together or not at all (ADR 0019).
 *
 * **This use case does not authorize its caller** (Identity cannot ask Access); the adapter must, first.
 */
final readonly class EnableAccount
{
    public function __construct(
        private AccountRepository $accounts,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccountNotFound
     */
    public function __invoke(AccountId $accountId, ?Actor $by = null): ReactivationOutcome
    {
        return $this->database->transaction(function () use ($accountId, $by): ReactivationOutcome {
            $account = $this->accounts->findForUpdate($accountId) ?? throw new AccountNotFound;
            if ($account->status !== AccountStatus::Disabled) {
                return ReactivationOutcome::NotDisabled;
            }

            $enabled = $account->enable(DateTimeImmutable::createFromInterface(now()));
            $this->accounts->save($enabled);

            ($this->record)(
                IdentityEvent::AccountReenabled->value, SecurityEventOutcome::Success,
                $by, $account->personId, $account->id, null, null,
                ['status' => $enabled->status->value],
            );

            return ReactivationOutcome::Enabled;
        }, 3);
    }
}
