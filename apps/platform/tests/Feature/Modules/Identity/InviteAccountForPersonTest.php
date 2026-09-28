<?php

declare(strict_types=1);

use App\Modules\Identity\Application\EmailAlreadyInUse;
use App\Modules\Identity\Application\InvalidInvitationDetails;
use App\Modules\Identity\Application\InvitationDelivery;
use App\Modules\Identity\Application\InvitationNotifier;
use App\Modules\Identity\Application\InviteAccountForPerson;
use App\Modules\Identity\Application\IssuedInvitation;
use App\Modules\Identity\Application\PersonHasAccount;
use App\Modules\Identity\Application\PersonNotFound;
use App\Modules\Identity\Domain\AccountInvitationId;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\InvitationChannel;
use App\Modules\Identity\Domain\InvitationToken;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Faults;
use Tests\Support\Identity;

/*
 * Identity\Application\InviteAccountForPerson (ADR 0032, Work Package 1): the generic capability to invite an
 * EXISTING Person — most likely one Membership registered with no Account (RegisterPerson, ADR 0028) — to a
 * Commons Account. InviteAccount's sibling, not a variant of it: no Person is ever created here.
 *
 * Behaviour this inherits verbatim from the already-proven InviteAccount seam (both use the same Account/
 * AccountInvitation domain objects, the same repositories, and the same token/channel machinery) is NOT
 * re-proven exhaustively here — see InviteAccountTest for that coverage. What is specific to THIS use case
 * is what is tested: no Person is created, the Person must already exist and have no Account, and the
 * channel is always email.
 */

function inviteExisting(PersonId $person, string $email = 'invitee@example.org', ?Actor $by = null): IssuedInvitation
{
    return app(InviteAccountForPerson::class)($person, $email, $by);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-28 12:00:00');
});

it('creates an INVITED account for the existing person, and creates no new person', function () {
    $person = Identity::savedPerson('Existing Person');
    $peopleBefore = DB::table('people')->count();

    $issued = inviteExisting($person->id, 'invitee@example.org');

    $account = app(AccountRepository::class)->find($issued->accountId);
    expect(DB::table('people')->count())->toBe($peopleBefore) // no new Person
        ->and($issued->personId)->toEqual($person->id)
        ->and($account?->personId)->toEqual($person->id)
        ->and($account?->status)->toBe(AccountStatus::Invited)
        ->and($account?->passwordHash)->toBeNull()
        ->and($account?->canAuthenticate())->toBeFalse()
        ->and(DB::table('account_invitations')->value('channel'))->toBe('email');
});

it('is always an EMAIL-channel invitation: there is no operator-handed variant of this use case', function () {
    $person = Identity::savedPerson();

    $issued = inviteExisting($person->id);

    $invitation = app(AccountInvitationRepository::class)->find(
        AccountInvitationId::fromString(Identity::scalar('account_invitations', 'id')),
    );
    expect($invitation?->channel)->toBe(InvitationChannel::Email);
});

it('stores only the hash of the token, and the raw secret is nowhere in the database', function () {
    $person = Identity::savedPerson();

    $issued = inviteExisting($person->id);

    $stored = DB::table('account_invitations')->first();
    expect($stored?->token_hash)->toBe(hash('sha256', $issued->revealToken()))
        ->and(app(AccountInvitationRepository::class)->findByToken(InvitationToken::fromPresented($issued->revealToken()))?->accountId)->toEqual($issued->accountId);
});

it('does not reveal the token in a dump of the result', function () {
    $person = Identity::savedPerson();

    $issued = inviteExisting($person->id);

    expect(print_r($issued, true))->not->toContain($issued->revealToken())
        ->and(var_export($issued->__debugInfo(), true))->not->toContain($issued->revealToken());
});

it('records account.invited with the subject and only the lifetime and channel in context', function () {
    $person = Identity::savedPerson();

    $issued = inviteExisting($person->id);

    $events = Identity::events('account.invited');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->subject_account_id)->toBe($issued->accountId->value)
        ->and($events[0]->subject_person_id)->toBe($person->id->value)
        ->and(Identity::context($events[0]))->toBe(['expires_in_days' => 7, 'channel' => 'email']);
});

it('records who invited, when an operator did', function () {
    $admin = Access::admin('admin@example.org');
    $person = Identity::savedPerson();

    inviteExisting($person->id, 'invitee@example.org', Access::actorFor($admin));

    expect(Identity::events('account.invited')[0]->actor_account_id)->toBe($admin->id->value);
});

it('refuses a Person that does not exist', function () {
    $counts = Faults::counts();

    expect(fn () => inviteExisting(PersonId::generate()))->toThrow(PersonNotFound::class);

    expect(Faults::counts())->toBe($counts);
});

it('refuses a Person who already has an Account, and touches nothing', function () {
    $existing = Identity::savedActiveAccount('has-account@example.org', name: 'Has Account');
    $counts = Faults::counts();

    expect(fn () => inviteExisting($existing->personId, 'new-address@example.org'))->toThrow(PersonHasAccount::class);

    expect(Faults::counts())->toBe($counts)
        ->and(DB::table('accounts')->where('person_id', $existing->personId->value)->count())->toBe(1);
});

it('refuses a Person who is already invited (an Account already exists, just not active)', function () {
    $invited = Identity::savedInvitedAccount('already-invited@example.org');

    expect(fn () => inviteExisting($invited->personId, 'other@example.org'))->toThrow(PersonHasAccount::class);
});

it('refuses an address already in use, in any case, and changes nothing', function (string $variant) {
    $person = Identity::savedPerson();
    $existing = Identity::savedActiveAccount('taken@example.org', name: 'Existing');
    $peopleBefore = DB::table('people')->count();

    expect(fn () => inviteExisting($person->id, $variant))->toThrow(EmailAlreadyInUse::class);

    expect(DB::table('people')->count())->toBe($peopleBefore)
        ->and(DB::table('accounts')->where('id', $existing->id->value)->value('email_canonical'))->toBe('taken@example.org')
        ->and(DB::table('accounts')->where('person_id', $person->id->value)->exists())->toBeFalse();
})->with(['taken@example.org', 'TAKEN@Example.ORG', '  taken@example.org ']);

it('rejects a malformed email address before anything is written', function (string $email) {
    $person = Identity::savedPerson();
    $counts = Faults::counts();

    expect(fn () => inviteExisting($person->id, $email))->toThrow(InvalidInvitationDetails::class);

    expect(Faults::counts())->toBe($counts);
})->with([
    'not an email' => ['not an email'],
    'non-ASCII address' => ['josé@example.org'],
    'empty' => [''],
]);

it('expires the invitation after the configured lifetime, seven days by default', function () {
    $person = Identity::savedPerson();

    inviteExisting($person->id);

    expect(DB::table('account_invitations')->value('expires_at'))->toBe('2026-10-05 12:00:00');
});

it('rolls the whole invitation back when the audit write fails', function () {
    $person = Identity::savedPerson();
    Faults::auditFailsAt(1);
    $counts = Faults::counts();

    expect(fn () => inviteExisting($person->id))->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(Faults::counts())->toBe($counts)
        ->and(DB::table('accounts')->where('person_id', $person->id->value)->exists())->toBeFalse();
});

it('rolls the account back when the invitation cannot be saved', function () {
    $person = Identity::savedPerson();
    Faults::invitationSaveFails();
    $counts = Faults::counts();

    expect(fn () => inviteExisting($person->id))->toThrow(RuntimeException::class, 'invitation write failed');

    expect(Faults::counts())->toBe($counts)
        ->and(DB::table('accounts')->where('person_id', $person->id->value)->exists())->toBeFalse();
});

it('does not mail before the invitation has committed: the message is not sent from inside the issuing transaction', function () {
    $sent = new ArrayObject;
    app()->instance(InvitationNotifier::class, new class($sent) implements InvitationNotifier
    {
        /** @param  ArrayObject<int, string>  $sent */
        public function __construct(private ArrayObject $sent) {}

        public function send(IssuedInvitation $invitation): InvitationDelivery
        {
            $this->sent[] = $invitation->email;

            return InvitationDelivery::Sent;
        }
    });
    $person = Identity::savedPerson();

    DB::transaction(function () use ($person, $sent): void {
        inviteExisting($person->id);
        expect(count($sent))->toBe(0);
    });

    expect(count($sent))->toBe(0); // nothing in this use case ever calls the notifier: that is DeliverInvitation's job
});

it('is not usable as a sign-in: the invited account cannot authenticate', function () {
    $person = Identity::savedPerson();

    inviteExisting($person->id, 'invitee@example.org');

    (new Console)->login('invitee@example.org', 'anything at all')->assertUnauthorized();
});
