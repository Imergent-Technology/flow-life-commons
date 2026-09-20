<?php

declare(strict_types=1);

use App\Modules\Identity\Application\AcceptInvitation;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\EnableAccount;
use App\Modules\Identity\Application\InvitationDetails;
use App\Modules\Identity\Application\InviteAccount;
use App\Modules\Identity\Application\ReissueInvitation;
use App\Modules\Identity\Domain\InvitationChannel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;
use Tests\Support\Passwords;
use Tests\Support\Race;

/*
 * Operator administration of accounts and invitations under REAL concurrency, across two PHP processes and two
 * database connections (method: Tests\Support\Race). The Phase 4 and 5 rule for the operations Phase 8 adds:
 * whatever committed while an operation waited on a row lock is seen AFTER it, and what it decides is decided on
 * that, never on what it read earlier.
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

it('makes an enable wait for a disable in flight, then enable on what the disable committed', function () {
    // The disable holds the Account's row lock and has not committed. The enable must not act on the Active state
    // it could have read: it waits, sees Disabled, and enables. Both are recorded, in that order.
    $account = Identity::savedActiveAccount('target@example.org');

    $race = Race::against(
        fn (Closure $pause) => app(DisableAccount::class)($account->id),
        'account.disabled', 'enable', ['account' => $account->id->value],
    );

    expect($race['blocked'])->toBeTrue('the enable did not wait for the disable to commit')
        ->and($race['exit'])->toBe(0)
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('status'))->toBe('active')
        ->and(array_map(fn ($e) => $e->type, Identity::events()))->toBe(['account.disabled', 'account.reenabled']);
});

it('lets exactly one of two simultaneous re-enables act: the second finds the Account no longer disabled', function () {
    $account = Identity::savedDisabledAccount('target@example.org');

    $race = Race::against(
        fn (Closure $pause) => app(EnableAccount::class)($account->id),
        'account.reenabled', 'enable', ['account' => $account->id->value],
    );

    expect($race['blocked'])->toBeTrue('the second enable did not wait')
        ->and($race['exit'])->toBe(2)     // refused: NotDisabled
        ->and(DB::table('accounts')->where('id', $account->id->value)->value('status'))->toBe('active')
        ->and(Identity::events('account.reenabled'))->toHaveCount(1);
});

it('refuses to accept an invitation that a reissue replaced while the acceptance waited', function () {
    // The reissue holds the invitation locks and has not committed. The acceptance of the OLD token waits, and
    // when it gets the row the row is gone: the old token is dead, the Account is still invited.
    $first = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);
    $token = $first->revealToken();

    $race = Race::against(
        fn (Closure $pause) => app(ReissueInvitation::class)($first->accountId),
        'invitation.reissued', 'accept', ['token' => $token, 'password' => Passwords::STRONG],
    );

    expect($race['blocked'])->toBeTrue('the acceptance did not wait for the reissue to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('accounts')->where('id', $first->accountId->value)->value('status'))->toBe('invited')
        ->and(DB::table('accounts')->value('email_verified_at'))->toBeNull()
        ->and(DB::table('account_invitations')->count())->toBe(1)
        ->and(DB::table('account_invitations')->value('token_hash'))->not->toBe(hash('sha256', $token));
});

it('refuses to reissue for an Account whose invitation was accepted while the reissue waited', function () {
    // The acceptance holds the invitation row and has not committed. The reissue waits, sees an ACTIVE Account,
    // and refuses: an accepted invitation is never followed by a fresh one, and nothing new is created.
    $first = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);

    $race = Race::against(
        fn (Closure $pause) => app(AcceptInvitation::class)($first->revealToken(), Passwords::STRONG, new ClientContext('127.0.0.1', 'first')),
        'invitation.accepted', 'reissue', ['account' => $first->accountId->value],
    );

    expect($race['blocked'])->toBeTrue('the reissue did not wait for the acceptance to commit')
        ->and($race['exit'])->toBe(2)
        ->and(DB::table('accounts')->where('id', $first->accountId->value)->value('status'))->toBe('active')
        ->and(DB::table('account_invitations')->count())->toBe(1)
        ->and(Identity::events('invitation.reissued'))->toBe([]);
});

it('creates one Account when two invitations for the same address are issued at once', function () {
    $race = Race::against(
        fn (Closure $pause) => app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'First'), null, InvitationChannel::Email),
        'account.invited', 'invite', ['email' => 'New@Example.ORG', 'name' => 'Second'],
    );

    expect($race['blocked'])->toBeTrue('the second invitation did not wait on the address')
        ->and($race['exit'])->toBe(2)
        ->and($race['class'])->toBe('App\\Modules\\Identity\\Application\\EmailAlreadyInUse')
        ->and(DB::table('accounts')->count())->toBe(1)
        ->and(DB::table('people')->count())->toBe(1) // the loser's Person did not survive
        ->and(DB::table('account_invitations')->count())->toBe(1);
});
