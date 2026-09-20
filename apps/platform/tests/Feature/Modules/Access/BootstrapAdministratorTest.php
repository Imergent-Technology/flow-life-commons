<?php

declare(strict_types=1);

use App\Modules\Access\Application\AdministratorAlreadyExists;
use App\Modules\Access\Application\AdministratorOverview;
use App\Modules\Access\Application\BootstrapAdministrator;
use App\Modules\Access\Application\BootstrapResult;
use App\Modules\Access\Application\ConsoleUserFixture;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Identity\Application\EmailAlreadyInUse;
use App\Modules\Identity\Application\InvitationDetails;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Faults;
use Tests\Support\Identity;

function bootstrapAdministrator(string $email = 'root@example.org', string $name = 'Root Administrator', bool $recovery = false): BootstrapResult
{
    return app(BootstrapAdministrator::class)(InvitationDetails::from($email, $name), $recovery);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 12:00:00');
});

// --- A clean system ---------------------------------------------------------------------------

it('creates exactly one person, one invited account, one administrator assignment and one invitation', function () {
    $result = bootstrapAdministrator();

    expect(DB::table('people')->count())->toBe(1)
        ->and(DB::table('accounts')->count())->toBe(1)
        ->and(DB::table('account_invitations')->count())->toBe(1)
        ->and(DB::table('role_assignments')->count())->toBe(1)
        ->and(DB::table('role_assignments')->value('role_key'))->toBe('platform_administrator')
        ->and(DB::table('role_assignments')->value('person_id'))->toBe($result->invitation->personId->value)
        ->and($result->recovery)->toBeFalse();
});

it('leaves the account INVITED with no password set', function () {
    $result = bootstrapAdministrator();

    $account = app(AccountRepository::class)->find($result->invitation->accountId);
    expect($account?->status)->toBe(AccountStatus::Invited)
        ->and($account?->passwordHash)->toBeNull()
        ->and($account?->passwordUpdatedAt)->toBeNull()
        ->and($account?->emailVerifiedAt)->toBeNull()
        ->and($account?->canAuthenticate())->toBeFalse();
});

it('does not yet make the administrator an ACTIVE administrator: that needs the invitation to be accepted', function () {
    bootstrapAdministrator();

    $summary = app(AdministratorOverview::class)();

    expect($summary->assigned)->toBe(1)->and($summary->active)->toBe(0);
});

it('stores only the hash of the invitation token, and issues it single-use and expiring', function () {
    $result = bootstrapAdministrator();

    $row = DB::table('account_invitations')->first();
    expect($row?->token_hash)->toBe(hash('sha256', $result->invitation->revealToken()))
        ->and($row?->accepted_at)->toBeNull()
        ->and($row?->expires_at)->toBe('2026-09-27 12:00:00')
        ->and($row?->invited_by_account_id)->toBeNull(); // issued by the platform, not by an account
});

it('returns the raw token only in the result: it is nowhere in the database, events included', function () {
    $result = bootstrapAdministrator();
    $token = $result->invitation->revealToken();

    expect(Faults::everythingStored())->not->toContain($token)
        ->and(print_r($result, true))->not->toContain($token);
});

it('audits the bootstrap, the invitation and the role grant, with no secret and no actor', function () {
    $result = bootstrapAdministrator();

    $types = array_map(fn (object $e): string => $e->type, Identity::events());
    sort($types);
    expect($types)->toBe(['account.invited', 'administrator.bootstrapped', 'role.granted']);

    foreach (Identity::events() as $event) {
        expect($event->actor_account_id)->toBeNull()                                   // server access, not an Actor
            ->and($event->subject_person_id)->toBe($result->invitation->personId->value)
            ->and($event->outcome)->toBe('success');
    }
    expect(Identity::context(Identity::events('administrator.bootstrapped')[0]))->toBe(['recovery' => false, 'existing_administrators' => 0])
        ->and(Identity::context(Identity::events('role.granted')[0]))->toBe(['role' => 'platform_administrator'])
        ->and(Faults::everythingStored())->not->toContain($result->invitation->revealToken());
});

it('takes no password and no authorization bypass: its only inputs are who, and whether it is a recovery', function () {
    $parameters = array_map(
        fn (ReflectionParameter $p): string => $p->getName().':'.(string) $p->getType(),
        (new ReflectionMethod(BootstrapAdministrator::class, '__invoke'))->getParameters(),
    );

    expect($parameters)->toBe(['details:App\Modules\Identity\Application\InvitationDetails', 'recovery:bool']);
});

// --- Refusing to bootstrap twice ------------------------------------------------------------------

it('refuses an ordinary second bootstrap, changing nothing', function () {
    bootstrapAdministrator('first@example.org', 'First');
    $counts = Faults::counts();

    expect(fn () => bootstrapAdministrator('second@example.org', 'Second'))->toThrow(AdministratorAlreadyExists::class);

    expect(Faults::counts())->toBe($counts);
});

it('refuses even when the existing administrator is unusable, since the assignment exists', function () {
    // An invited or disabled administrator still makes an ordinary bootstrap refuse: recovery is
    // an explicit act, not something the system infers.
    $invited = Identity::savedInvitedAccount('invited@example.org');
    Access::grant($invited, Role::PlatformAdministrator);

    expect(fn () => bootstrapAdministrator())->toThrow(AdministratorAlreadyExists::class);
});

it('does not count a guardian as an administrator, so bootstrap still proceeds', function () {
    $guardian = Identity::savedActiveAccount('guardian@example.org', name: 'Guardian');
    Access::grant($guardian, Role::Guardian);

    bootstrapAdministrator();

    expect(DB::table('role_assignments')->where('role_key', 'platform_administrator')->count())->toBe(1);
});

// --- Recovery ---------------------------------------------------------------------------------------

it('creates ANOTHER administrator on an explicit recovery, leaving the existing one untouched', function () {
    $existing = Access::admin('existing@example.org', 'Existing');
    $before = [DB::table('accounts')->where('id', $existing->id->value)->first(), DB::table('people')->where('id', $existing->personId->value)->first()];

    $result = bootstrapAdministrator('recovery@example.org', 'Recovery', recovery: true);

    expect($result->recovery)->toBeTrue()
        ->and(DB::table('role_assignments')->where('role_key', 'platform_administrator')->count())->toBe(2)
        ->and([DB::table('accounts')->where('id', $existing->id->value)->first(), DB::table('people')->where('id', $existing->personId->value)->first()])->toEqual($before)
        ->and(Identity::context(Identity::events('administrator.bootstrapped')[0]))->toBe(['recovery' => true, 'existing_administrators' => 1]);
});

it('never repurposes an existing account on recovery: an address in use fails and changes nothing', function () {
    $existing = Access::admin('existing@example.org', 'Existing');
    $victim = Identity::savedActiveAccount('victim@example.org', name: 'Victim');
    $counts = Faults::counts();
    $snapshot = [DB::table('accounts')->get()->all(), DB::table('role_assignments')->get()->all()];

    expect(fn () => bootstrapAdministrator('victim@example.org', 'Hijack', recovery: true))->toThrow(EmailAlreadyInUse::class)
        ->and(fn () => bootstrapAdministrator('VICTIM@example.org', 'Hijack', recovery: true))->toThrow(EmailAlreadyInUse::class);

    expect(Faults::counts())->toBe($counts)
        ->and([DB::table('accounts')->get()->all(), DB::table('role_assignments')->get()->all()])->toEqual($snapshot)
        ->and(app(RoleAssignmentRepository::class)->forPerson($victim->personId))->toBe([]) // no authority conferred on them
        ->and($existing->id)->not->toBeNull();
});

it('fails on a duplicate address on an ordinary bootstrap too, before creating anything', function () {
    Identity::savedActiveAccount('taken@example.org', name: 'Taken');
    $counts = Faults::counts();

    expect(fn () => bootstrapAdministrator('taken@example.org', 'Someone'))->toThrow(EmailAlreadyInUse::class);

    expect(Faults::counts())->toBe($counts);
});

// --- Atomicity ----------------------------------------------------------------------------------------

it('leaves nothing behind when any single step fails', function (string $failure) {
    match ($failure) {
        'the first audit write' => Faults::auditFailsAt(1),
        'the role grant audit write' => Faults::auditFailsAt(2),
        'the bootstrap audit write (the very last step)' => Faults::auditFailsAt(3),
        'the account write' => Faults::accountSaveFails(),
        'the invitation write' => Faults::invitationSaveFails(),
        'the role assignment write' => Faults::roleAssignmentFails(),
        default => throw new InvalidArgumentException($failure),
    };
    $counts = Faults::counts();

    $result = null;
    expect(function () use (&$result): void {
        $result = bootstrapAdministrator();
    })->toThrow(RuntimeException::class);

    // No Person, Account, assignment, invitation or event survives, and no result (so no token) was ever returned.
    expect(Faults::counts())->toBe($counts)->and($result)->toBeNull();
})->with([
    'the first audit write',
    'the role grant audit write',
    'the bootstrap audit write (the very last step)',
    'the account write',
    'the invitation write',
    'the role assignment write',
]);

it('rolls back a failed recovery just the same, leaving the existing administrator intact', function () {
    $existing = Access::admin('existing@example.org', 'Existing');
    Faults::auditFailsAt(3);
    $counts = Faults::counts();

    expect(fn () => bootstrapAdministrator('recovery@example.org', 'Recovery', recovery: true))->toThrow(RuntimeException::class);

    expect(Faults::counts())->toBe($counts)
        ->and(Access::activeAdministrators())->toBe(1)
        ->and($existing->id)->not->toBeNull();
});

// --- The development fixture (no role named outside Access) ----------------------------------------------------

it('gives a person the Console\'s ordinary role through Access, in a testing environment', function () {
    $account = Identity::savedActiveAccount();

    app(ConsoleUserFixture::class)($account->personId);
    app(ConsoleUserFixture::class)($account->personId); // idempotent

    expect(DB::table('role_assignments')->where('person_id', $account->personId->value)->count())->toBe(1)
        ->and(app(RoleAssignmentRepository::class)->forPerson($account->personId)[0]->roleKey)->toBe('guardian');
});

it('refuses to run anywhere but a local or testing environment', function (string $environment) {
    $account = Identity::savedActiveAccount();
    config(['app.env' => $environment]);

    expect(fn () => app(ConsoleUserFixture::class)($account->personId))->toThrow(LogicException::class);

    expect(DB::table('role_assignments')->count())->toBe(0);
})->with(['production', 'staging', '']);

it('grants no administrator authority, whatever it is asked', function () {
    $account = Identity::savedActiveAccount();

    app(ConsoleUserFixture::class)($account->personId);

    expect(Access::activeAdministrators())->toBe(0)
        ->and(app(RoleAssignmentRepository::class)->holdersOf('platform_administrator'))->toBe([])
        ->and(PersonId::generate())->not->toBeNull();
});
