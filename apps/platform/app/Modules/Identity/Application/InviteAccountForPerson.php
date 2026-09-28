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
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\EmailAddressAlreadyInUse;
use App\Modules\Identity\Domain\InvalidEmailAddress;
use App\Modules\Identity\Domain\InvitationChannel;
use App\Modules\Identity\Domain\InvitationToken;
use App\Modules\Identity\Domain\PersonAlreadyHasAccount as DomainPersonAlreadyHasAccount;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;

/**
 * Creates an Account and a single-use, EMAIL-channel invitation for a Person who already exists — a member
 * Membership registered with no Account (`RegisterPerson`, ADR 0028), or anyone else already known to Identity —
 * and records `account.invited`.
 *
 * This is `InviteAccount`'s sibling, not a variant of it: `InviteAccount` always creates a NEW Person, and nothing
 * before this could give an EXISTING one an Account. It is a generic Identity capability (ADR 0032), not a
 * Membership-specific one — Membership may end up the first caller, but this class knows nothing about membership.
 *
 * - **No Person is created.** The supplied `PersonId` must already exist; this never calls `Person::create()`.
 * - **The channel is always Email.** There is no operator-handed variant: an existing Person able to be invited
 *   this way is, by construction, being invited to an address the platform will mail the token to (ADR 0024).
 * - **A Person holds at most one Account** (ADR 0015). Refused cleanly if the Person already has one, whether
 *   that is read before the write (the ordinary case) or discovered by the database's own unique constraint on
 *   `accounts.person_id` losing a race with a concurrent invitation of the same Person — exactly how `InviteAccount`
 *   already handles the equivalent race on `accounts.email_canonical`.
 * - **It sets no password**, stores only the token's hash, and shows the raw secret to nobody before this
 *   transaction commits — identical to `InviteAccount`, for the identical reason (the token must never be stored).
 * - **Nothing is sent from here.** Delivery is `DeliverInvitation`'s, after commit, exactly as for `InviteAccount`.
 *
 * **This use case does not authorize its caller.** Identity cannot ask Access what a caller may do; the capability
 * that governs this is the same one that governs `InviteAccount` (`identity.invitations.issue`), checked by
 * whichever Access use case exposes this.
 */
final readonly class InviteAccountForPerson
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
     * @throws PersonNotFound the Person does not exist
     * @throws PersonHasAccount the Person already has an Account
     * @throws EmailAlreadyInUse another Account already holds this email address
     * @throws InvalidInvitationDetails the email address is not acceptable
     */
    public function __invoke(PersonId $personId, string $email, ?Actor $invitedBy = null): IssuedInvitation
    {
        try {
            $address = EmailAddress::fromString($email);
        } catch (InvalidEmailAddress) {
            throw new InvalidInvitationDetails('That is not a valid email address (printable ASCII; internationalised domains as punycode).');
        }

        $ttlDays = max(1, $this->config->integer('identity.invitation.ttl_days'));

        return $this->database->transaction(function () use ($personId, $address, $invitedBy, $ttlDays): IssuedInvitation {
            $person = $this->people->find($personId) ?? throw new PersonNotFound;

            // Clean refusals, without an INSERT that would abort the transaction on PostgreSQL. The database's own
            // unique constraints are what actually make these race-safe; these reads just avoid the common case
            // paying for a doomed write.
            if ($this->accounts->findByPersonId($person->id) !== null) {
                throw new PersonHasAccount;
            }
            if ($this->accounts->findByEmail($address) !== null) {
                throw new EmailAlreadyInUse;
            }

            $now = DateTimeImmutable::createFromInterface(now());
            $expiresAt = $now->modify("+{$ttlDays} days");

            $account = Account::invite(AccountId::generate(), $person->id, $address, $now);
            $token = InvitationToken::generate();
            $invitation = AccountInvitation::issue(
                AccountInvitationId::generate(), $account->id, $token, $expiresAt, $now, $invitedBy?->accountId, InvitationChannel::Email,
            );

            try {
                $this->accounts->save($account);
            } catch (EmailAddressAlreadyInUse) {
                throw new EmailAlreadyInUse; // lost a race for the same address; the constraint held
            } catch (DomainPersonAlreadyHasAccount) {
                throw new PersonHasAccount; // lost a race for the same Person; the constraint held
            }
            $this->invitations->save($invitation);

            ($this->record)(
                IdentityEvent::AccountInvited->value, SecurityEventOutcome::Success,
                $invitedBy, $person->id, $account->id, null, null,
                ['expires_in_days' => $ttlDays, 'channel' => InvitationChannel::Email->value],
            );

            return new IssuedInvitation($person->id, $account->id, $address->value, $expiresAt, $token);
        });
    }
}
