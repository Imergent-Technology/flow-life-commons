<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Domain\AccountInvitation;
use App\Modules\Identity\Domain\AccountInvitationId;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\InvitationChannel;
use App\Modules\Identity\Domain\InvitationToken;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;

/**
 * Issues a FRESH invitation for an Account that is still invited: the old one expired, the message was lost, or
 * delivery failed. The Account and Person are untouched, and it is still invite-only (ADR 0015).
 *
 * - **Exactly one usable invitation afterwards.** Every earlier invitation of the Account that was never accepted
 *   is DELETED in the same transaction (revocation is deleting the row), so the old token stops working the moment
 *   the new one exists, and there are never two at once.
 * - **Locks in acceptance's order** (invitations, then the Account) so the two queue behind one another and cannot
 *   deadlock. Whatever committed while this waited is what it decides on: an invitation accepted meanwhile makes
 *   the Account active and this refuses; one this waited on and found deleted just means it goes on to replace it.
 * - **Only for an INVITED Account.** An active Account is never re-invited (that would be a way round its
 *   password), and a disabled one must be enabled first.
 * - A new high-entropy secret, hashed for storage. The raw secret comes back inside IssuedInvitation and must reach
 *   no one before this has committed; DeliverInvitation is what sends it.
 *
 * **This use case does not authorize its caller** (Identity cannot ask Access). Access's use case does, first.
 */
final readonly class ReissueInvitation
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountInvitationRepository $invitations,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
        private Config $config,
    ) {}

    /**
     * @throws AccountNotFound
     * @throws InvitationNotIssuable the Account is not invited
     */
    public function __invoke(AccountId $accountId, ?Actor $by = null, InvitationChannel $channel = InvitationChannel::Email): IssuedInvitation
    {
        $ttlDays = max(1, $this->config->integer('identity.invitation.ttl_days'));

        return $this->database->transaction(function () use ($accountId, $by, $channel, $ttlDays): IssuedInvitation {
            $this->invitations->lockAllFor($accountId); // 1. the invitations, as acceptance does
            $account = $this->accounts->findForUpdate($accountId) ?? throw new AccountNotFound; // 2. then the Account
            if ($account->status !== AccountStatus::Invited) {
                throw new InvitationNotIssuable($account->status->value);
            }

            $now = DateTimeImmutable::createFromInterface(now());
            $expiresAt = $now->modify("+{$ttlDays} days");
            $token = InvitationToken::generate();

            $revoked = $this->invitations->deleteUnacceptedFor($accountId);
            $this->invitations->save(AccountInvitation::issue(
                AccountInvitationId::generate(), $accountId, $token, $expiresAt, $now, $by?->accountId, $channel,
            ));

            ($this->record)(
                IdentityEvent::InvitationReissued->value, SecurityEventOutcome::Success,
                $by, $account->personId, $account->id, null, null,
                ['expires_in_days' => $ttlDays, 'channel' => $channel->value, 'replaced' => $revoked],
            );

            return new IssuedInvitation($account->personId, $account->id, $account->email->value, $expiresAt, $token);
        }, 3);
    }
}
