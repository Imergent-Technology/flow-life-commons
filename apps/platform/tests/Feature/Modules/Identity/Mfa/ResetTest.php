<?php

declare(strict_types=1);

use App\Modules\Access\Application\Role;
use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\ResetMultiFactor;
use App\Modules\Identity\Application\SelfMfaResetProhibited;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorId;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Shared\Domain\AccountId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Console;
use Tests\Support\Faults;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Totp;

/*
 * Administrative and server-level recovery of a lost second factor (ADR 0024). The use case has no authority of its
 * own: Access authorizes the administrator path, and the operator's command is the server one.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
});

/**
 * The operator doing the resetting, and a Console user with a second factor.
 *
 * @return array{Account, Account, array{secret: string, codes: list<string>}}
 */
function resetScene(): array
{
    $admin = Access::admin('admin@example.org');
    $target = Mfa::guardian('target@example.org');
    $factor = Mfa::enroll($target);

    return [$admin, $target, $factor];
}

it('removes the authenticator and every recovery code, and ends every session of the target', function () {
    [$admin, $target, $factor] = resetScene();
    $targetConsole = new Console;
    $targetConsole->loginWithMfa('target@example.org', Identity::PASSWORD, $factor['secret'])->assertOk();
    $otherConsole = new Console;
    Mfa::enroll($admin, Totp::RFC_SECRET);
    $otherConsole->loginWithMfa('admin@example.org', Identity::PASSWORD, Totp::RFC_SECRET)->assertOk();

    $result = app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), $target->id);

    expect($result->changed)->toBeTrue()
        ->and($result->sessionsEnded)->toBe(1)
        ->and(Mfa::isEnrolled($target))->toBeFalse()
        ->and(Mfa::factorRow($target))->toBeNull()
        ->and(DB::table('account_recovery_codes')->where('account_id', $target->id->value)->count())->toBe(0);
    $targetConsole->me()->assertUnauthorized();      // the target's session is gone
    $otherConsole->me()->assertOk();                 // nobody else's is touched
});

it('leaves the password, the status, the roles, the Person and their history exactly as they were', function () {
    [$admin, $target] = resetScene();
    $before = [
        DB::table('accounts')->where('id', $target->id->value)->first(['password_hash', 'status', 'email', 'email_verified_at']),
        DB::table('role_assignments')->where('person_id', $target->personId->value)->pluck('role_key')->all(),
        DB::table('people')->where('id', $target->personId->value)->first(),
    ];
    $events = DB::table('security_events')->count();

    app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), $target->id);

    expect([
        DB::table('accounts')->where('id', $target->id->value)->first(['password_hash', 'status', 'email', 'email_verified_at']),
        DB::table('role_assignments')->where('person_id', $target->personId->value)->pluck('role_key')->all(),
        DB::table('people')->where('id', $target->personId->value)->first(),
    ])->toEqual($before)
        ->and(DB::table('security_events')->count())->toBe($events + 1); // history kept; only the reset is added
});

it('records who did it, whom it was done to, and counts, and nothing secret', function () {
    [$admin, $target, $factor] = resetScene();

    app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), $target->id);

    $events = Identity::events('mfa.administratively_reset');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and($events[0]->subject_account_id)->toBe($target->id->value)
        ->and($events[0]->subject_person_id)->toBe($target->personId->value)
        ->and(Identity::context($events[0]))->toBe(['had_authenticator' => true, 'recovery_codes_removed' => 10, 'signed_out' => 0]);
    Mfa::assertAbsent(Mfa::auditText(), $factor['secret'], ...$factor['codes']);
});

it('REFUSES to reset the acting Account\'s own second factor, changes nothing and records nothing', function () {
    $admin = Access::admin('admin@example.org');
    $factor = Mfa::enroll($admin);

    expect(fn () => app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), $admin->id))
        ->toThrow(SelfMfaResetProhibited::class);

    expect(Mfa::isEnrolled($admin))->toBeTrue()
        ->and(Mfa::remainingCodes($admin))->toBe(10)
        ->and(Identity::events('mfa.administratively_reset'))->toBe([])
        ->and($factor['secret'])->not->toBe('');
});

it('does nothing, and records nothing, for an Account that has no second factor at all', function () {
    $admin = Access::admin('admin@example.org');
    $target = Mfa::guardian('target@example.org');

    $result = app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), $target->id);

    expect($result->changed)->toBeFalse()->and(Identity::events('mfa.administratively_reset'))->toBe([]);
});

it('also removes a PENDING enrolment that was never proved', function () {
    $admin = Access::admin('admin@example.org');
    $target = Mfa::guardian('target@example.org');
    app(TotpFactorRepository::class)->save(TotpFactor::begin(TotpFactorId::generate(), $target->id, 'ciphertext', Identity::now()));

    $result = app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), $target->id);

    expect($result->changed)->toBeTrue()->and(Mfa::factorRow($target))->toBeNull();
});

it('fails for an unknown Account', function () {
    $admin = Access::admin('admin@example.org');

    expect(fn () => app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), AccountId::generate()))->toThrow(AccountNotFound::class);
});

it('sends the target to ENROLMENT at their next sign-in: the old code and the old recovery codes no longer work', function () {
    [$admin, $target, $factor] = resetScene();
    app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), $target->id);

    $console = new Console;
    $console->login('target@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);

    // Neither the old authenticator nor an old recovery code is accepted: this is not a challenge any more.
    $console->post('/api/v1/mfa/challenge', ['code' => Totp::next($factor['secret'])])->assertUnauthorized();
    $again = new Console;
    $again->login('target@example.org', Identity::PASSWORD)->assertStatus(202);
    $again->post('/api/v1/mfa/challenge', ['recovery_code' => $factor['codes'][0]])->assertUnauthorized();
    $again->me()->assertUnauthorized();
});

it('lets the target enrol a NEW authenticator, and only then reach the Console', function () {
    [$admin, $target] = resetScene();
    app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), $target->id);
    $console = new Console;
    $console->login('target@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);

    $secret = Mfa::text($console->post('/api/v1/mfa/enrollment')->assertOk()->json('secret'));
    $console->me()->assertUnauthorized();
    $confirmed = $console->post('/api/v1/mfa/enrollment/confirm', ['code' => Totp::next($secret)])->assertOk();

    expect($confirmed->json('recovery_codes'))->toHaveCount(10)
        ->and(Mfa::isEnrolled($target))->toBeTrue();
    $console->me()->assertOk();
});

it('defeats a sign-in that was half finished when the reset committed: it can no longer be completed', function () {
    // The password was accepted and the challenge is pending. The pending sign-in has no Account attached to its
    // session row, so ending "the Account's sessions" cannot reach it; what stops it is that finishing it needs a
    // LIVE factor, and there is none.
    [$admin, $target, $factor] = resetScene();
    $console = new Console;
    $console->login('target@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'challenge']);

    app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), $target->id);

    $console->challenge($factor['secret'])->assertStatus(422);
    $console->me()->assertUnauthorized();
    expect(Identity::events('authentication.succeeded'))->toBe([])
        ->and(DB::table('accounts')->where('id', $target->id->value)->value('last_login_at'))->toBeNull();
});

it('lets the server reset the SOLE administrator, with no Actor, and records it as a server reset', function () {
    $only = Access::admin('root@example.org');
    Mfa::enroll($only);

    $result = app(ResetMultiFactor::class)->fromServer($only->id);

    $events = Identity::events('mfa.reset_from_server');
    expect($result->changed)->toBeTrue()
        ->and($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBeNull()
        ->and($events[0]->subject_account_id)->toBe($only->id->value)
        ->and(Identity::events('mfa.administratively_reset'))->toBe([])
        // The administrator role is not touched: an MFA reset is not the removal of administrator authority.
        ->and(Access::activeAdministrators())->toBe(1);
    (new Console)->login('root@example.org', Identity::PASSWORD)->assertStatus(202)->assertJson(['next' => 'enrollment']);
});

it('rolls the whole reset back when its audit write fails', function () {
    [$admin, $target] = resetScene();
    Faults::auditFailsAt(1);

    expect(fn () => app(ResetMultiFactor::class)->byAdministrator(Access::actorFor($admin), $target->id))->toThrow(RuntimeException::class)
        ->and(Mfa::isEnrolled($target))->toBeTrue()
        ->and(Mfa::remainingCodes($target))->toBe(10);
});
