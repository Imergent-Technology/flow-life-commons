<?php

declare(strict_types=1);

use App\Modules\Identity\Application\AcceptInvitation;
use App\Modules\Identity\Application\ChangePassword;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\CurrentPasswordIncorrect;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\InvitationRejected;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Application\RequestPasswordReset;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Application\ResetRejected;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvitationToken;
use App\Shared\Domain\Actor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Identity;
use Tests\Support\Passwords;
use Tests\Support\Race;
use Tests\Support\Recovery;

/*
 * Credential changes under REAL concurrency, across two PHP processes and two database
 * connections (method: Tests\Support\Race).
 *
 * The rule under test is the Phase 4 lesson applied to credentials: an Account read before a
 * security-sensitive race window must never be saved back or acted on as if it were still current.
 * Whatever committed while a flow waited on the Account's row lock has to be seen after it.
 *
 * Runs on MariaDB and PostgreSQL: `./flow test backend` and `./flow test backend --pgsql`.
 */

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
    Race::clean();
});

afterEach(function () {
    Race::clean();
});

it('does not let a sign-in that verified the old password succeed once that password has been replaced', function () {
    // The sign-in reads the Account and checks the password against the hash it saw, then waits
    // for the row lock while a change to the credential is still uncommitted. When the lock is
    // released the stored hash is different: what it proved is a password that no longer exists.
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($target, $pause): void {
            app(AccountRepository::class)->save(Identity::withPasswordHash($target, Hash::make('a brand new passphrase')));
            $pause();
        }),
        null, 'login', ['email' => 'target@example.org', 'password' => Identity::PASSWORD],
    );

    expect($race['blocked'])->toBeTrue('the sign-in did not wait for the credential change to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0)
        ->and(DB::table('accounts')->whereNotNull('last_login_at')->count())->toBe(0);
});

it('still signs in, after waiting, when the concurrent change left the credential alone', function () {
    // The control: the lock makes the sign-in WAIT, and it succeeds because the stored hash is
    // still the one it verified. Without it the test above could pass by refusing everything.
    $target = Identity::savedActiveAccount('target@example.org', name: 'Target');

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($target, $pause): void {
            $current = app(AccountRepository::class)->findForUpdate($target->id);
            assert($current !== null);
            app(AccountRepository::class)->save($current); // an unrelated write to the same row
            $pause();
        }),
        null, 'login', ['email' => 'target@example.org', 'password' => Identity::PASSWORD],
    );

    expect($race['blocked'])->toBeTrue()
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('accounts')->whereNotNull('last_login_at')->count())->toBe(1);
});

/**
 * A pending invitation for a fresh invited Account, committed like everything here.
 *
 * @return array{Account, string} the Account and the raw token
 */
function committedInvitation(string $email = 'invitee@example.org'): array
{
    $account = Identity::savedInvitedAccount($email);
    $token = InvitationToken::generate();
    app(AccountInvitationRepository::class)->save(Identity::invitation($account, $token));

    return [$account, $token->reveal()];
}

it('lets exactly one of two simultaneous acceptances of the same invitation succeed', function () {
    // The first acceptance is paused inside its transaction, invitation locked and password set but
    // not committed. A second, real, process presents the same token with a different password.
    [$account, $token] = committedInvitation();

    $race = Race::against(
        fn (Closure $pause) => app(AcceptInvitation::class)($token, Passwords::STRONG, new ClientContext('127.0.0.1', 'first')),
        'invitation.accepted', 'accept', ['token' => $token, 'password' => Passwords::OTHER],
    );

    expect($race['blocked'])->toBeTrue('the second acceptance did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(InvitationRejected::class)
        ->and(DB::table('security_events')->where('type', 'invitation.accepted')->count())->toBe(1)
        ->and(DB::table('account_invitations')->whereNotNull('accepted_at')->count())->toBe(1)
        // The password is the first caller's, not the second's.
        ->and(Hash::check(Passwords::STRONG, Identity::scalar('accounts', 'password_hash')))->toBeTrue()
        ->and(Hash::check(Passwords::OTHER, Identity::scalar('accounts', 'password_hash')))->toBeFalse();
});

it('cannot resurrect an account that was disabled while its acceptance waited', function () {
    // A disable is committed-pending. The acceptance has read the invitation and is waiting for the
    // Account's row. When it gets it the Account is disabled, and it must stay that way.
    [$account, $token] = committedInvitation();

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($account->id),
        'account.disabled', 'accept', ['token' => $token, 'password' => Passwords::STRONG],
    );

    $row = DB::table('accounts')->where('id', $account->id->value)->first();
    expect($race['blocked'])->toBeTrue('the acceptance did not wait for the disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(InvitationRejected::class)
        ->and($row?->status)->toBe('disabled')
        ->and($row?->password_hash)->toBeNull()
        ->and(DB::table('account_invitations')->whereNotNull('accepted_at')->count())->toBe(0)
        ->and(DB::table('security_events')->where('type', 'invitation.accepted')->count())->toBe(0);
});

it('lets a disable that arrives while an acceptance is in flight win, without being overwritten', function () {
    // The other order: the acceptance holds the locks; the disable waits, then applies on top of it.
    [$account, $token] = committedInvitation();

    $race = Race::against(
        fn (Closure $pause) => app(AcceptInvitation::class)($token, Passwords::STRONG, new ClientContext('127.0.0.1', 'first')),
        'invitation.accepted', 'disable', ['account' => $account->id->value],
    );

    expect($race['blocked'])->toBeTrue('the disable did not wait for the acceptance to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('status'))->toBe('disabled');
});

it('locks the invitation row, so a second reader waits for the first to finish', function () {
    // The acceptance's one-time guarantee has two independent layers (this lock and the Account's state
    // under its own lock). The race tests above pass through the second even if this one is removed, so
    // the invitation lock is pinned here directly.
    [, $token] = committedInvitation();

    $race = Race::against(
        fn (Closure $pause) => DB::transaction(function () use ($token, $pause): void {
            app(AccountInvitationRepository::class)->findByTokenForUpdate(InvitationToken::fromPresented($token));
            $pause();
        }),
        null, 'lock_invitation', ['token' => $token],
    );

    expect($race['blocked'])->toBeTrue('a second locking read of the invitation did not wait')
        ->and($race['exit'])->toBe(0);
});

/**
 * An active Account with a live reset token, committed.
 *
 * @return array{Account, string} the Account and the raw token
 */
function committedResetToken(string $email = 'ada@example.org'): array
{
    $account = Identity::savedActiveAccount($email);

    return [$account, Recovery::tokenFor($account)];
}

/** Runs a reset in this process, as the first (paused) operation of a race. */
function resetHere(string $token, string $password, string $email = 'ada@example.org'): void
{
    app(ResetPassword::class)(EmailAddress::fromString($email), $token, $password, new ClientContext('127.0.0.1', 'first'));
}

it('lets exactly one of two simultaneous resets with the same token succeed', function () {
    [$account, $token] = committedResetToken();

    $race = Race::against(
        fn (Closure $pause) => resetHere($token, Passwords::STRONG),
        'password.reset_completed', 'reset', ['email' => 'ada@example.org', 'token' => $token, 'password' => Passwords::OTHER],
    );

    $hash = Identity::scalar('accounts', 'password_hash');
    expect($race['blocked'])->toBeTrue('the second reset did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(ResetRejected::class)
        ->and(DB::table('security_events')->where('type', 'password.reset_completed')->count())->toBe(1)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        // The password is the first caller's, not the second's.
        ->and(Hash::check(Passwords::STRONG, $hash))->toBeTrue()
        ->and(Hash::check(Passwords::OTHER, $hash))->toBeFalse();
});

it('cannot undo a disable that was committed while the reset waited for the account', function () {
    // The disable holds the account's row uncommitted. The reset has passed every earlier check and is
    // waiting for the same row. When it gets it the account is disabled, and it must stay that way,
    // with its password untouched.
    [$account, $token] = committedResetToken();
    $before = Identity::scalar('accounts', 'password_hash');

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($account->id),
        'account.disabled', 'reset', ['email' => 'ada@example.org', 'token' => $token, 'password' => Passwords::STRONG],
    );

    expect($race['blocked'])->toBeTrue('the reset did not wait for the disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(ResetRejected::class)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('status'))->toBe('disabled')
        ->and(Identity::scalar('accounts', 'password_hash'))->toBe($before)
        ->and(DB::table('security_events')->where('type', 'password.reset_completed')->count())->toBe(0);
});

it('lets a disable that arrives while a reset is in flight win, after it', function () {
    [$account, $token] = committedResetToken();

    $race = Race::against(
        fn (Closure $pause) => resetHere($token, Passwords::STRONG),
        'password.reset_completed', 'disable', ['account' => $account->id->value],
    );

    expect($race['blocked'])->toBeTrue('the disable did not wait for the reset to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('status'))->toBe('disabled');
});

it('does not let a sign-in that verified the old password succeed once a reset has replaced it', function () {
    // The scenario of the corrective commit, through the real use case: the sign-in checked the old
    // password against the old hash, then waited for the account while the reset was uncommitted.
    [$account, $token] = committedResetToken();

    $race = Race::against(
        fn (Closure $pause) => resetHere($token, Passwords::STRONG),
        'password.reset_completed', 'login', ['email' => 'ada@example.org', 'password' => Identity::PASSWORD],
    );

    expect($race['blocked'])->toBeTrue('the sign-in did not wait for the reset to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('last_login_at'))->toBeNull();
});

it('serialises two simultaneous requests for one account: one token, and no error', function () {
    // Without the account lock, the second request would try to insert a second row for the address
    // and fail on its primary key: a 500 that also tells a caller the address is real.
    Identity::savedActiveAccount('ada@example.org');

    $race = Race::against(
        fn (Closure $pause) => app(RequestPasswordReset::class)(EmailAddress::fromString('ada@example.org'), new ClientContext('127.0.0.1', 'first')),
        'password.reset_requested', 'request_reset', ['email' => 'ada@example.org'],
    );

    $events = Identity::events('password.reset_requested');
    expect($race['blocked'])->toBeTrue('the second request did not wait for the first to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('password_reset_tokens')->count())->toBe(1)
        ->and(array_map(fn (object $e): string => $e->outcome, $events))->toBe(['success', 'failure'])
        ->and(Identity::context($events[1]))->toMatchArray(['reason' => 'recently_requested']);
});

it('does not issue a token to an account that was disabled while the request waited for it', function () {
    Identity::savedActiveAccount('ada@example.org');
    $account = app(AccountRepository::class)->findByEmail(EmailAddress::fromString('ada@example.org'));
    assert($account !== null);

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($account->id),
        'account.disabled', 'request_reset', ['email' => 'ada@example.org'],
    );

    expect($race['blocked'])->toBeTrue('the request did not wait for the disable to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(Identity::context(Identity::events('password.reset_requested')[0]))->toMatchArray(['reason' => 'account_not_eligible']);
});

/** Runs a password change in this process, as the first (paused) operation of a race. */
function changeHere(Account $account, string $current, string $new): void
{
    app(ChangePassword::class)(
        Actor::user($account->id, $account->personId), $current, $new, 'the-first-callers-session', new ClientContext('127.0.0.1', 'first'),
    );
}

it('verifies the current password against the credential as a concurrent change left it', function () {
    // Two changes from two sessions of the same account, both presenting the same current password.
    // The second waits for the first, then finds its "current" password is no longer the current one.
    $account = Identity::savedActiveAccount('ada@example.org');

    $race = Race::against(
        fn (Closure $pause) => changeHere($account, Identity::PASSWORD, Passwords::STRONG),
        'password.changed', 'change', [
            'account' => $account->id->value, 'person' => $account->personId->value,
            'current' => Identity::PASSWORD, 'password' => Passwords::OTHER,
        ],
    );

    $hash = Identity::scalar('accounts', 'password_hash');
    expect($race['blocked'])->toBeTrue('the second change did not wait for the first to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(CurrentPasswordIncorrect::class)
        ->and(DB::table('security_events')->where('type', 'password.changed')->count())->toBe(1)
        ->and(Hash::check(Passwords::STRONG, $hash))->toBeTrue()
        ->and(Hash::check(Passwords::OTHER, $hash))->toBeFalse();
});

it('cannot change the password of an account that was disabled while the change waited for it', function () {
    $account = Identity::savedActiveAccount('ada@example.org');
    $before = Identity::scalar('accounts', 'password_hash');

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($account->id),
        'account.disabled', 'change', [
            'account' => $account->id->value, 'person' => $account->personId->value,
            'current' => Identity::PASSWORD, 'password' => Passwords::STRONG,
        ],
    );

    expect($race['blocked'])->toBeTrue('the change did not wait for the disable to commit')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe(NoLongerAuthenticated::class)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('status'))->toBe('disabled')
        ->and(Identity::scalar('accounts', 'password_hash'))->toBe($before)
        ->and(DB::table('security_events')->where('type', 'password.changed')->count())->toBe(0);
});

it('lets a disable that arrives while a change is in flight win, after it', function () {
    $account = Identity::savedActiveAccount('ada@example.org');

    $race = Race::against(
        fn (Closure $pause) => changeHere($account, Identity::PASSWORD, Passwords::STRONG),
        'password.changed', 'disable', ['account' => $account->id->value],
    );

    expect($race['blocked'])->toBeTrue('the disable did not wait for the change to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('status'))->toBe('disabled');
});

it('does not let a sign-in that verified the old password succeed once it has been changed', function () {
    $account = Identity::savedActiveAccount('ada@example.org');

    $race = Race::against(
        fn (Closure $pause) => changeHere($account, Identity::PASSWORD, Passwords::STRONG),
        'password.changed', 'login', ['email' => 'ada@example.org', 'password' => Identity::PASSWORD],
    );

    expect($race['blocked'])->toBeTrue('the sign-in did not wait for the change to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
});
