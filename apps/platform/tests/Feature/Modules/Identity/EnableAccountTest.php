<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\EnableAccount;
use App\Modules\Identity\Application\ReactivationOutcome;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\InvalidAccountState;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Faults;
use Tests\Support\Identity;
use Tests\Support\Mfa;

function enable(AccountId $id, ?Actor $by = null): ReactivationOutcome
{
    return app(EnableAccount::class)($id, $by);
}

it('re-enables a disabled Account, back to ACTIVE, and records who did it', function () {
    $admin = Access::admin('admin@example.org');
    $account = Identity::savedDisabledAccount('target@example.org');

    $outcome = enable($account->id, Access::actorFor($admin));

    $row = DB::table('accounts')->where('id', $account->id->value)->first();
    $events = Identity::events('account.reenabled');
    expect($outcome)->toBe(ReactivationOutcome::Enabled)
        ->and($row?->status)->toBe('active')
        ->and($row?->disabled_at)->toBeNull()
        ->and($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and($events[0]->subject_account_id)->toBe($account->id->value)
        ->and(Identity::context($events[0]))->toBe(['status' => 'active']);
});

it('puts an Account that never chose a password back to INVITED, not active', function () {
    $invited = Identity::savedInvitedAccount();
    app(DisableAccount::class)($invited->id);

    enable($invited->id);

    $account = app(AccountRepository::class)->find($invited->id);
    expect($account?->status)->toBe(AccountStatus::Invited)
        ->and($account?->passwordHash)->toBeNull()
        ->and($account?->canAuthenticate())->toBeFalse();
});

it('creates NO session, changes no password and no role, and keeps the Person and the history', function () {
    $account = Identity::savedActiveAccount('target@example.org');
    Access::grant($account, Role::Guardian);
    $console = new Console;
    $console->login('target@example.org', Identity::PASSWORD); // a pending sign-in or session exists...
    app(DisableAccount::class)($account->id);                 // ...and disabling ends every session of the Account
    $hash = DB::table('accounts')->where('id', $account->id->value)->value('password_hash');
    $assignments = DB::table('role_assignments')->count();
    $people = DB::table('people')->count();
    $events = DB::table('security_events')->count();

    enable($account->id);

    expect(DB::table('sessions')->where('user_id', $account->id->value)->count())->toBe(0)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('password_hash'))->toBe($hash)
        ->and(DB::table('role_assignments')->count())->toBe($assignments)
        ->and(DB::table('people')->count())->toBe($people)
        ->and(DB::table('security_events')->count())->toBe($events + 1) // only account.reenabled is added: nothing is lost
        ->and($console->me()->status())->toBe(401);
});

it('does NOT bypass MFA: an enabled Console user with a second factor still has to present one', function () {
    $account = Mfa::guardian('target@example.org');
    $factor = Mfa::enroll($account);
    app(DisableAccount::class)($account->id);
    enable($account->id);

    $console = new Console;
    $console->login('target@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'challenge']);

    expect($console->me()->status())->toBe(401);
    $console->challenge($factor['secret'])->assertOk();
});

it('requires enrolment on next sign-in when the second factor was reset before the Account was re-enabled', function () {
    $account = Mfa::guardian('target@example.org');
    Mfa::enroll($account);
    app(DisableAccount::class)($account->id);
    DB::table('account_totp_factors')->delete();
    DB::table('account_recovery_codes')->delete();
    enable($account->id);

    (new Console)->login('target@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);
});

it('refuses an Account that is not disabled, and changes and records nothing', function () {
    $active = Identity::savedActiveAccount();
    $before = DB::table('security_events')->count();

    expect(enable($active->id))->toBe(ReactivationOutcome::NotDisabled)
        ->and(DB::table('accounts')->where('id', $active->id->value)->value('status'))->toBe('active')
        ->and(DB::table('security_events')->count())->toBe($before);
});

it('fails for an unknown Account', function () {
    expect(fn () => enable(AccountId::generate()))->toThrow(AccountNotFound::class);
});

it('cannot enable at the domain level unless the Account is disabled', function () {
    expect(fn () => Identity::savedActiveAccount('a@example.org')->enable(Identity::now()))->toThrow(InvalidAccountState::class);
});

it('rolls the re-enable back when its audit write fails', function () {
    $account = Identity::savedDisabledAccount();
    Faults::auditFailsAt(1);

    expect(fn () => enable($account->id))->toThrow(RuntimeException::class)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('status'))->toBe('disabled');
});
