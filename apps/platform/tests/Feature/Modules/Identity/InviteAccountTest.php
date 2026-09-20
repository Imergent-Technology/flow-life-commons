<?php

declare(strict_types=1);

use App\Modules\Identity\Application\EmailAlreadyInUse;
use App\Modules\Identity\Application\InvalidInvitationDetails;
use App\Modules\Identity\Application\InvitationDetails;
use App\Modules\Identity\Application\InviteAccount;
use App\Modules\Identity\Application\IssuedInvitation;
use App\Modules\Identity\Domain\AccountInvitationId;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\InvitationToken;
use App\Shared\Domain\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Faults;
use Tests\Support\Identity;

function invite(string $email = 'invitee@example.org', string $name = 'Ivy Invitee', ?Actor $by = null): IssuedInvitation
{
    return app(InviteAccount::class)(InvitationDetails::from($email, $name), $by);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 12:00:00');
});

it('creates a person, an INVITED account with no credential, and a single-use invitation', function () {
    $issued = invite();

    $account = app(AccountRepository::class)->find($issued->accountId);
    expect($account?->status)->toBe(AccountStatus::Invited)
        ->and($account?->passwordHash)->toBeNull()
        ->and($account?->passwordUpdatedAt)->toBeNull()
        ->and($account?->emailVerifiedAt)->toBeNull()
        ->and($account?->canAuthenticate())->toBeFalse()
        ->and(DB::table('people')->where('id', $issued->personId->value)->value('display_name'))->toBe('Ivy Invitee')
        ->and(DB::table('account_invitations')->count())->toBe(1)
        ->and(DB::table('account_invitations')->value('accepted_at'))->toBeNull();
});

it('stores only the hash of the token, and the raw secret is nowhere in the database', function () {
    $issued = invite();

    $stored = DB::table('account_invitations')->first();
    expect($stored?->token_hash)->toBe(hash('sha256', $issued->revealToken()))
        ->and($issued->revealToken())->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(Faults::everythingStored())->not->toContain($issued->revealToken())
        // ...and it is the token that finds the invitation, by hash.
        ->and(app(AccountInvitationRepository::class)->findByToken(InvitationToken::fromPresented($issued->revealToken()))?->accountId)->toEqual($issued->accountId);
});

it('does not reveal the token in a dump of the result', function () {
    $issued = invite();

    expect(print_r($issued, true))->not->toContain($issued->revealToken())
        ->and(var_export($issued->__debugInfo(), true))->not->toContain($issued->revealToken());
});

it('expires the invitation after the configured lifetime, seven days by default', function () {
    invite();
    expect(DB::table('account_invitations')->value('expires_at'))->toBe('2026-09-27 12:00:00');

    config(['identity.invitation.ttl_days' => 3]);
    invite('other@example.org', 'Other');
    expect(DB::table('account_invitations')->where('expires_at', '2026-09-23 12:00:00')->count())->toBe(1);
});

it('is not usable as a sign-in: the invited account cannot authenticate', function () {
    invite('invitee@example.org');

    (new Console)->login('invitee@example.org', 'anything at all')->assertUnauthorized();
});

it('records account.invited with the subject and only the lifetime in context', function () {
    $issued = invite();

    $events = Identity::events('account.invited');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBeNull()
        ->and($events[0]->subject_account_id)->toBe($issued->accountId->value)
        ->and($events[0]->subject_person_id)->toBe($issued->personId->value)
        ->and(Identity::context($events[0]))->toBe(['expires_in_days' => 7, 'channel' => 'operator']);
});

it('records who invited, when an account did', function () {
    $admin = Access::admin('admin@example.org');

    $issued = invite('invitee@example.org', 'Ivy', Access::actorFor($admin));

    expect(app(AccountInvitationRepository::class)->find(AccountInvitationId::fromString(Identity::scalar('account_invitations', 'id')))?->invitedByAccountId)->toEqual($admin->id)
        ->and(Identity::events('account.invited')[0]->actor_account_id)->toBe($admin->id->value)
        ->and($issued->email)->toBe('invitee@example.org');
});

it('refuses an address already in use, in any case, and changes nothing', function (string $variant) {
    $existing = Identity::savedActiveAccount('taken@example.org', name: 'Existing');
    $before = DB::table('accounts')->where('id', $existing->id->value)->first();
    $counts = Faults::counts();

    expect(fn () => invite($variant, 'Someone Else'))->toThrow(EmailAlreadyInUse::class);

    expect(Faults::counts())->toBe($counts)
        ->and(DB::table('accounts')->where('id', $existing->id->value)->first())->toEqual($before);
})->with(['taken@example.org', 'TAKEN@Example.ORG', '  taken@example.org ']);

it('refuses an address held by an invited or a disabled account, too', function () {
    Identity::savedInvitedAccount('invited@example.org');
    Identity::savedDisabledAccount('disabled@example.org');

    expect(fn () => invite('invited@example.org'))->toThrow(EmailAlreadyInUse::class)
        ->and(fn () => invite('disabled@example.org'))->toThrow(EmailAlreadyInUse::class);
});

it('rejects details Identity would not accept', function (string $email, string $name) {
    $counts = Faults::counts();

    expect(fn () => InvitationDetails::from($email, $name))->toThrow(InvalidInvitationDetails::class)
        ->and(Faults::counts())->toBe($counts);
})->with([
    'not an email' => ['not an email', 'Name'],
    'non-ASCII address' => ['josé@example.org', 'Name'],
    'empty name' => ['a@example.org', '   '],
    'name too long' => ['a@example.org', str_repeat('x', 256)],
]);

it('rolls the whole invitation back when the audit write fails', function () {
    Faults::auditFailsAt(1);
    $counts = Faults::counts();

    expect(fn () => invite())->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(Faults::counts())->toBe($counts);
});

it('rolls the person and account back when the invitation cannot be saved', function () {
    Faults::invitationSaveFails();
    $counts = Faults::counts();

    expect(fn () => invite())->toThrow(RuntimeException::class, 'invitation write failed');

    expect(Faults::counts())->toBe($counts);
});
