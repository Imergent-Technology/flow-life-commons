<?php

declare(strict_types=1);

use App\Modules\Identity\Application\PruneTransientState;
use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorId;
use App\Modules\Identity\Domain\TotpFactorRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Recovery;
use Tests\Support\Totp;

use function Pest\Laravel\artisan;

/*
 * Deterministic maintenance of Identity's transient state (`identity:prune-expired`), which replaces
 * Laravel's per-request session-sweeping lottery.
 *
 * The property that matters is not "it deletes things" but "it deletes ONLY things that are already
 * unusable": a sweep that could end a live session would be worse than the accumulation it fixes. Every
 * case below therefore pairs what must go with what must stay.
 */

/**
 * `artisan()` returns PendingCommand|int; every call here needs the object.
 *
 * @param  array<array-key, mixed>  $arguments
 */
function commandSessionMaintenance(string $name, array $arguments = []): PendingCommand
{
    $pending = artisan($name, $arguments);
    assert($pending instanceof PendingCommand);

    return $pending;
}

/** A session row as the framework writes them, idle for `$idleMinutes`. */
function idleSession(string $id, int $idleMinutes, ?string $accountId = null): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $accountId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => base64_encode(json_encode([], JSON_THROW_ON_ERROR)),
        'last_activity' => Carbon::now()->subMinutes($idleMinutes)->getTimestamp(),
    ]);
}

it('removes sessions idle past the inactivity lifetime and keeps the rest', function () {
    expect(config()->integer('session.lifetime'))->toBe(30);

    idleSession('fresh', 1);
    idleSession('just-inside', 29);
    idleSession('just-outside', 31);
    idleSession('ancient', 60 * 24 * 30);

    expect(app(PruneTransientState::class)()->sessions)->toBe(2)
        ->and(Identity::sessionIds())->toEqualCanonicalizing(['fresh', 'just-inside']);
});

it('cannot end a session anyone is still using', function () {
    // The strongest form of the property: sign in for real, sweep, and still be signed in.
    [$console] = Mfa::signedIn();
    $console->me()->assertOk();

    expect(app(PruneTransientState::class)()->sessions)->toBe(0);

    $console->me()->assertOk();
});

it('removes an authenticated session only once it is idle past the same cutoff', function () {
    // Authenticated and anonymous sessions follow one idle rule; nothing exempts a signed-in row.
    [$console, $account] = Mfa::signedIn();
    $console->me()->assertOk();

    Carbon::setTestNow(Carbon::now()->addMinutes(31));

    expect(app(PruneTransientState::class)()->sessions)->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $account->id->value)->count())->toBe(0);
});

it('changes nothing when it is run again', function () {
    idleSession('gone', 60);
    idleSession('kept', 1);

    $first = app(PruneTransientState::class)();
    $second = app(PruneTransientState::class)();

    expect($first->sessions)->toBe(1)
        ->and($second->total())->toBe(0)
        ->and(Identity::sessionIds())->toBe(['kept']);
});

it('deletes more rows than one batch holds', function () {
    // The adapter deletes in pages of 1000. A sweep after a long outage must clear the whole backlog,
    // not one page of it, so the loop is exercised rather than assumed.
    for ($i = 0; $i < 1205; $i++) {
        idleSession('stale-'.$i, 60);
    }
    idleSession('live', 1);

    expect(app(PruneTransientState::class)()->sessions)->toBe(1205)
        ->and(Identity::sessionIds())->toBe(['live']);
});

it('removes expired password-reset tokens and keeps live ones', function () {
    $expired = Identity::savedActiveAccount('old@example.org');
    Recovery::tokenFor($expired);
    Carbon::setTestNow(Carbon::now()->addMinutes(config()->integer('auth.passwords.accounts.expire') + 1));
    $live = Identity::savedActiveAccount('new@example.org');
    $token = Recovery::tokenFor($live);

    expect(app(PruneTransientState::class)()->passwordResetTokens)->toBe(1)
        ->and(DB::table('password_reset_tokens')->pluck('email')->all())->toBe([$live->email->canonical]);

    // The surviving token still works: pruning removed rows, not the ability to finish a reset.
    Recovery::reset($live->email->value, $token, 'a completely different long passphrase')->assertNoContent();
});

it('forgets an authenticator secret that was generated and never proved', function () {
    $account = Mfa::guardian();
    app(TotpFactorRepository::class)->save(TotpFactor::begin(
        TotpFactorId::generate(), $account->id, 'ciphertext', Identity::now(),
    ));
    Carbon::setTestNow(Carbon::now()->addSeconds(TotpFactor::PENDING_LIFETIME_SECONDS + 1));

    expect(app(PruneTransientState::class)()->pendingAuthenticators)->toBe(1)
        ->and(Mfa::factorRow($account))->toBeNull();
});

it('keeps a proved authenticator while forgetting the stale replacement beside it', function () {
    // The other half of the domain rule: a factor with an ACTIVE secret keeps it and merely loses the
    // pending one, because an enrolment must hold an active secret, a pending one, or both.
    [$console, $account, $factor] = Mfa::signedIn();
    $console->post(Console::API.'/mfa/authenticator', [
        'current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret']),
    ])->assertOk();
    expect(Mfa::factorRow($account)?->pending_secret_ciphertext)->not->toBeNull();

    Carbon::setTestNow(Carbon::now()->addSeconds(TotpFactor::PENDING_LIFETIME_SECONDS + 1));

    expect(app(PruneTransientState::class)()->pendingAuthenticators)->toBe(1);
    $row = Mfa::factorRow($account);
    expect($row?->pending_secret_ciphertext)->toBeNull()
        ->and($row?->secret_ciphertext)->not->toBeNull()
        ->and(Mfa::isEnrolled($account))->toBeTrue();

    // And the person can still sign in with the authenticator they proved.
    $signedIn = new Console;
    $signedIn->loginWithMfa($account->email->value, Identity::PASSWORD, $factor['secret'])->assertOk();
});

it('leaves a fresh pending secret alone', function () {
    [$console, $account, $factor] = Mfa::signedIn();
    $console->post(Console::API.'/mfa/authenticator', [
        'current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret']),
    ])->assertOk();

    expect(app(PruneTransientState::class)()->pendingAuthenticators)->toBe(0)
        ->and(Mfa::factorRow($account)?->pending_secret_ciphertext)->not->toBeNull();
});

it('never touches the audit trail, invitations or accounts', function () {
    // The explicit retention decisions, pinned. `security_events` is append-only history; an expired,
    // unaccepted invitation is state an operator still needs to see.
    $account = Identity::savedInvitedAccount();
    Identity::savedInvitation($account, null, 'PT1S');
    [$console] = Mfa::signedIn('operator@example.org');
    $console->me();
    Carbon::setTestNow(Carbon::now()->addDays(365));

    $events = DB::table('security_events')->count();
    app(PruneTransientState::class)();

    expect(DB::table('security_events')->count())->toBe($events)
        ->and(DB::table('account_invitations')->count())->toBe(1)
        ->and(DB::table('accounts')->count())->toBe(2)
        ->and(DB::table('people')->count())->toBe(2);
});

it('is scheduled, not left to chance', function () {
    $events = app(Schedule::class)->events();
    $commands = array_map(static fn ($event): string => (string) $event->command, $events);

    expect(implode(' ', $commands))->toContain('identity:prune-expired')
        ->and(array_filter($events, static fn ($e): bool => str_contains((string) $e->command, 'identity:prune-expired')))
        ->toHaveCount(1);
});

it('does not rely on the per-request session lottery', function () {
    // Deterministic maintenance replaces it (config/session.php). A non-zero chance here would mean an
    // ordinary request could still be made to pay for a sweep.
    expect(config('session.lottery'))->toBe([0, 100]);
});

it('runs as a command and says what it removed', function () {
    idleSession('stale', 60);

    commandSessionMaintenance('identity:prune-expired')
        ->expectsOutputToContain('Pruned 1 idle session(s)')
        ->assertSuccessful();
});
