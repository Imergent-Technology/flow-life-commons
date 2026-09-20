<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountInvitation;
use App\Modules\Identity\Domain\AccountInvitationId;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddressAlreadyInUse;
use App\Modules\Identity\Domain\InvitationToken;
use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;

/**
 * Creates a Person, an INVITED Account (no credential) and a single-use, expiring invitation for
 * a new human, and records `account.invited`. Accounts are created only by invitation (ADR 0015).
 *
 * - It sets no password. The invitee sets their own when they accept, which is a later phase; the
 *   Account stays `invited` and cannot authenticate until then.
 * - It stores only the token's SHA-256 hash. The raw secret comes back inside IssuedInvitation
 *   and must not be shown to anyone before the transaction that wrote it commits: when this runs
 *   inside a larger transaction (the administrator bootstrap), that is the caller's commit.
 * - An email address already in use fails cleanly and touches nothing: it never repurposes or
 *   merges an existing Account.
 * - Nothing is sent. Delivery is not part of this phase.
 *
 * **This use case does not authorize its caller.** Identity cannot ask Access what a caller may
 * do. Whichever adapter exposes it must decide who may invite first; today the only one is the
 * administrator bootstrap command, whose authority is server access (ADR 0020).
 */
final readonly class InviteAccount
{
    public function __construct(
        private PersonRepository $people,
        private AccountRepository $accounts,
        private AccountInvitationRepository $invitations,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
        private Config $config,
    ) {}

    /**
     * @throws EmailAlreadyInUse
     */
    public function __invoke(InvitationDetails $details, ?Actor $invitedBy = null): IssuedInvitation
    {
        $ttlDays = max(1, $this->config->integer('identity.invitation.ttl_days'));

        return $this->database->transaction(function () use ($details, $invitedBy, $ttlDays): IssuedInvitation {
            // A clean refusal, without an INSERT that would abort the transaction on PostgreSQL.
            if ($this->accounts->findByEmail($details->email()) !== null) {
                throw new EmailAlreadyInUse;
            }

            $now = DateTimeImmutable::createFromInterface(now());
            $expiresAt = $now->modify("+{$ttlDays} days");

            $person = Person::create(PersonId::generate(), $details->displayName(), $now);
            $account = Account::invite(AccountId::generate(), $person->id, $details->email(), $now);
            $token = InvitationToken::generate();
            $invitation = AccountInvitation::issue(
                AccountInvitationId::generate(), $account->id, $token, $expiresAt, $now, $invitedBy?->accountId,
            );

            $this->people->save($person);
            try {
                $this->accounts->save($account);
            } catch (EmailAddressAlreadyInUse) {
                throw new EmailAlreadyInUse; // lost a race for the same address; the constraint held
            }
            $this->invitations->save($invitation);

            ($this->record)(
                IdentityEvent::AccountInvited->value, SecurityEventOutcome::Success,
                $invitedBy, $person->id, $account->id, null, null,
                ['expires_in_days' => $ttlDays],
            );

            return new IssuedInvitation($person->id, $account->id, $details->email()->value, $expiresAt, $token);
        });
    }
}
