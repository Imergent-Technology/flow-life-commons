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
use App\Modules\Identity\Domain\InvitationChannel;
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
 * - Nothing is sent from here: delivery is DeliverInvitation's, after the transaction that created this has
 *   committed. The method chosen says how the token WILL reach its holder, and decides whether accepting it shows
 *   mailbox control (ADR 0024): `byEmail` only for a caller that then mails it to the address. The bootstrap hands
 *   the token to a server operator and so uses the plain invocation.
 *
 * **This use case does not authorize its caller.** Identity cannot ask Access what a caller may
 * do. Whichever adapter exposes it must decide who may invite first: the administrator bootstrap command
 * (authority: server access, ADR 0020) and Access's operator-invitation use case (authority: a capability).
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
     * An invitation whose token an OPERATOR hands over (the administrator bootstrap): accepting it shows nothing
     * about the mailbox.
     *
     * @throws EmailAlreadyInUse
     */
    public function __invoke(InvitationDetails $details, ?Actor $invitedBy = null): IssuedInvitation
    {
        return $this->issue($details, $invitedBy, InvitationChannel::Operator);
    }

    /**
     * An invitation the platform WILL MAIL to the address (DeliverInvitation, after the commit): accepting it shows the
     * holder reached that mailbox. Only a caller that then delivers it by email may use this. The channel is a Domain
     * concept, so callers in other modules choose it by which method they call, never by naming it.
     *
     * @throws EmailAlreadyInUse
     */
    public function byEmail(InvitationDetails $details, ?Actor $invitedBy = null): IssuedInvitation
    {
        return $this->issue($details, $invitedBy, InvitationChannel::Email);
    }

    private function issue(InvitationDetails $details, ?Actor $invitedBy, InvitationChannel $channel): IssuedInvitation
    {
        $ttlDays = max(1, $this->config->integer('identity.invitation.ttl_days'));

        return $this->database->transaction(function () use ($details, $invitedBy, $ttlDays, $channel): IssuedInvitation {
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
                AccountInvitationId::generate(), $account->id, $token, $expiresAt, $now, $invitedBy?->accountId, $channel,
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
                ['expires_in_days' => $ttlDays, 'channel' => $channel->value],
            );

            return new IssuedInvitation($person->id, $account->id, $details->email()->value, $expiresAt, $token);
        });
    }
}
