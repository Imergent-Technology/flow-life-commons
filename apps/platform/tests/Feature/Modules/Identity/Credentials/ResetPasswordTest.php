<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\EmailAddress;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Console;
use Tests\Support\CountingHasher;
use Tests\Support\Faults;
use Tests\Support\Identity;
use Tests\Support\Passwords;
use Tests\Support\Recovery;

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:30:00');
    Passwords::breached();
});

/** @return array{Account, string} an active Account and a live reset token for it */
function accountWithToken(string $email = 'ada@example.org'): array
{
    $account = Identity::savedActiveAccount($email);

    return [$account, Recovery::tokenFor($account)];
}

/** The reset changed nothing: the password, the token and the account are as they were. */
function expectResetChangedNothing(Account $account): void
{
    $row = DB::table('accounts')->where('id', $account->id->value)->first();
    expect($row?->password_hash)->toBe($account->passwordHash)
        ->and($row?->status)->toBe($account->status->value)
        ->and(Identity::events('password.reset_completed'))->toBe([]);
}

it('changes the password: the new one signs in and the old one does not', function () {
    [$account, $token] = accountWithToken();

    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertNoContent();

    $row = DB::table('accounts')->where('id', $account->id->value)->first();
    expect($row?->password_updated_at)->toBe('2026-09-19 12:30:00')
        ->and($row?->status)->toBe('active')
        ->and($row?->email_verified_at)->toBe($account->emailVerifiedAt?->format('Y-m-d H:i:s'))
        // Not a sign-in: a reset does not touch when the account last signed in.
        ->and($row?->last_login_at)->toBeNull();

    (new Console)->login('ada@example.org', Passwords::STRONG)->assertOk();
    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertUnauthorized();
});

it('does not sign the caller in: no session, no cookie', function () {
    [, $token] = accountWithToken();

    $response = Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertNoContent();

    expect($response->baseResponse->headers->getCookies())->toBe([])
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('uses the token once', function () {
    [, $token] = accountWithToken();

    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertNoContent();
    Recovery::reset('ada@example.org', $token, Passwords::OTHER)->assertUnprocessable();

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    (new Console)->login('ada@example.org', Passwords::STRONG)->assertOk();
    (new Console)->login('ada@example.org', Passwords::OTHER)->assertUnauthorized();
});

it('honours the token until it expires and refuses it after', function () {
    [, $token] = accountWithToken('early@example.org');
    [, $late] = accountWithToken('late@example.org');

    Carbon::setTestNow('2026-09-19 13:29:59'); // 59 minutes 59 seconds after issue
    Recovery::reset('early@example.org', $token, Passwords::STRONG)->assertNoContent();

    Carbon::setTestNow('2026-09-19 13:30:01'); // 60 minutes 1 second after issue
    Recovery::reset('late@example.org', $late, Passwords::STRONG)->assertUnprocessable();
});

it('gives ONE answer whatever is wrong with the request, and does not leak which addresses have accounts', function () {
    [, $token] = accountWithToken('ada@example.org');
    $bob = Identity::savedActiveAccount('bob@example.org', name: 'Bob');
    $bobToken = Recovery::tokenFor($bob);
    Identity::savedInvitedAccount('invited@example.org');
    Identity::savedDisabledAccount('disabled@example.org');

    $cases = [
        'wrong token' => ['ada@example.org', str_repeat('a', 64)],
        'malformed token' => ['ada@example.org', 'x'],
        "someone else's token" => ['ada@example.org', $bobToken],
        'unknown address' => ['nobody@example.org', $token],
        'invited account' => ['invited@example.org', $token],
        'disabled account' => ['disabled@example.org', $token],
        'account with no token' => ['bob@example.org', str_repeat('b', 64)],
    ];
    Carbon::setTestNow('2026-09-19 14:00:00');
    $cases['expired token'] = ['ada@example.org', $token];

    $bodies = [];
    foreach ($cases as $why => [$email, $sent]) {
        $bodies[$why] = Recovery::reset($email, $sent, Passwords::STRONG)->assertUnprocessable()->getContent();
    }

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(json_decode((string) reset($bodies), true))->toHaveKey('errors.token');
});

it('records a failed reset with a reason class, and no identity for an unknown address', function () {
    [$account] = accountWithToken('ada@example.org');

    Recovery::reset('ada@example.org', str_repeat('a', 64), Passwords::STRONG)->assertUnprocessable();
    Recovery::reset('Nobody@Example.org', str_repeat('a', 64), Passwords::STRONG)->assertUnprocessable();

    $events = Identity::events('password.reset_failed');
    expect($events)->toHaveCount(2)
        ->and($events[0]->outcome)->toBe('failure')
        ->and(Identity::context($events[0]))->toBe(['reason' => 'invalid_token', 'attempted_identifier' => 'ada@example.org'])
        ->and($events[0]->subject_account_id)->toBe($account->id->value)
        ->and(Identity::context($events[1]))->toBe(['reason' => 'unknown_account', 'attempted_identifier' => 'nobody@example.org'])
        ->and($events[1]->subject_account_id)->toBeNull()
        ->and($events[1]->actor_account_id)->toBeNull();
    foreach (Identity::events() as $event) {
        expect(json_encode($event))->not->toContain(str_repeat('a', 64));
    }
});

it('never re-enables a disabled account, even with a token issued while it was active', function () {
    [$account, $token] = accountWithToken();
    $before = DB::table('accounts')->where('id', $account->id->value)->value('password_hash');
    app(DisableAccount::class)($account->id);

    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertUnprocessable();

    $row = DB::table('accounts')->where('id', $account->id->value)->first();
    expect($row?->status)->toBe('disabled')
        ->and($row?->disabled_at)->not->toBeNull()
        ->and($row?->password_hash)->toBe($before);
    (new Console)->login('ada@example.org', Passwords::STRONG)->assertUnauthorized();
    expect(Identity::events('password.reset_completed'))->toBe([]);
});

it('never activates an invited account, even if a token somehow exists for it', function () {
    $invited = Identity::savedInvitedAccount('ada@example.org');
    $token = Recovery::tokenFor($invited);

    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertUnprocessable();

    $row = DB::table('accounts')->where('id', $invited->id->value)->first();
    expect($row?->status)->toBe('invited')
        ->and($row?->password_hash)->toBeNull()
        ->and($row?->email_verified_at)->toBeNull();
});

it('ends every session the account has, and leaves everyone else\'s alone', function () {
    // Real database sessions: two browsers signed in as Ada, one as Bob, and an anonymous visitor.
    [$ada, $token] = accountWithToken('ada@example.org');
    Identity::savedActiveAccount('bob@example.org', name: 'Bob');
    $laptop = new Console;
    $phone = new Console;
    $bob = new Console;
    $laptop->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $phone->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $bob->login('bob@example.org', Identity::PASSWORD)->assertOk();
    (new Console)->bootstrap();
    expect(DB::table('sessions')->where('user_id', $ada->id->value)->count())->toBe(2)
        ->and(DB::table('sessions')->count())->toBe(4);

    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $ada->id->value)->count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBe(2);
    $laptop->me()->assertUnauthorized();
    $phone->me()->assertUnauthorized();
    $bob->me()->assertOk();
    expect(Identity::context(Identity::events('password.reset_completed')[0]))->toBe(['signed_out' => 2]);
});

it('records password.reset_completed for the account, without inventing an actor', function () {
    [$account, $token] = accountWithToken();

    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertNoContent();

    $events = Identity::events('password.reset_completed');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBeNull()
        ->and($events[0]->subject_account_id)->toBe($account->id->value)
        ->and($events[0]->subject_person_id)->toBe($account->personId->value);
});

it('matches the address in any case, against the token keyed by its canonical form', function () {
    [, $token] = accountWithToken('Ada@Example.org');

    Recovery::reset('  ADA@EXAMPLE.ORG', $token, Passwords::STRONG)->assertNoContent();

    (new Console)->login('ada@example.org', Passwords::STRONG)->assertOk();
});

it('refuses a token that was issued for a different address', function () {
    [, $adaToken] = accountWithToken('ada@example.org');
    Identity::savedActiveAccount('bob@example.org', name: 'Bob');

    Recovery::reset('bob@example.org', $adaToken, Passwords::STRONG)->assertUnprocessable();

    (new Console)->login('bob@example.org', Passwords::STRONG)->assertUnauthorized();
});

it('holds the password to the same policy, and a refused password does not spend the token', function () {
    [$account, $token] = accountWithToken();

    Recovery::reset('ada@example.org', $token, 'too short')->assertUnprocessable()
        ->assertJsonPath('errors.password.0', fn (string $m): bool => str_contains($m, 'at least 15'));
    Recovery::reset('ada@example.org', $token, str_repeat('a', 73))->assertUnprocessable();
    Recovery::reset('ada@example.org', $token, Passwords::STRONG, Passwords::OTHER)->assertUnprocessable()
        ->assertJsonValidationErrors(['password_confirmation']);

    expectResetChangedNothing($account);
    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertNoContent(); // still usable
});

it('refuses a compromised password, and keeps the token', function () {
    Passwords::breached('correct horse battery staple');
    [$account, $token] = accountWithToken();

    Recovery::reset('ada@example.org', $token, 'correct horse battery staple')->assertUnprocessable()
        ->assertJsonPath('errors.password.0', fn (string $m): bool => str_contains($m, 'data breaches'));

    expectResetChangedNothing($account);
    expect(DB::table('password_reset_tokens')->count())->toBe(1);
});

it('does NOT accept the password when the breach check is down: a retryable 503, and the token survives', function () {
    Passwords::checkerDown();
    [$account, $token] = accountWithToken();

    $response = Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertStatus(503);

    expect($response->headers->get('Retry-After'))->toBe('30');
    expectResetChangedNothing($account);
    Passwords::breached();
    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertNoContent();
});

it('runs the breach check before its own transaction, never inside it', function () {
    $fake = Passwords::breached();
    [, $token] = accountWithToken();
    $baseline = DB::transactionLevel();

    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertNoContent();

    expect($fake->depths)->toBe([$baseline]);
});

it('accepts a Unicode password whichever way it is spelled, and keeps edge spaces', function () {
    [, $token] = accountWithToken();
    $typed = '  ünïcödé passphrase ✓  ';

    Recovery::reset('ada@example.org', $token, Passwords::decomposed($typed))->assertNoContent();

    (new Console)->login('ada@example.org', $typed)->assertOk();
    (new Console)->login('ada@example.org', trim($typed))->assertUnauthorized();
});

it('rolls everything back if the audit event cannot be written: password, token and sessions', function () {
    [$account, $token] = accountWithToken();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();
    Faults::auditFailsAt(1);

    expect(fn () => app(ResetPassword::class)(EmailAddress::fromString('ada@example.org'), $token, Passwords::STRONG, new ClientContext('127.0.0.1', 'test')))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expectResetChangedNothing($account);
    expect(DB::table('password_reset_tokens')->count())->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $account->id->value)->count())->toBe(1);
    $console->me()->assertOk();
});

it('rolls everything back if the new password cannot be saved', function () {
    [$account, $token] = accountWithToken();
    Faults::accountSaveFails();

    expect(fn () => app(ResetPassword::class)(EmailAddress::fromString('ada@example.org'), $token, Passwords::STRONG, new ClientContext('127.0.0.1', 'test')))
        ->toThrow(RuntimeException::class, 'account write failed');

    expect(DB::table('password_reset_tokens')->count())->toBe(1);
    expectResetChangedNothing($account);
});

it('limits completions per identifier and per address, and counts refused passwords too', function () {
    config(['identity.credential_throttle.password_reset_completion.per_identifier' => 2]);
    accountWithToken();

    Recovery::reset('ada@example.org', str_repeat('a', 64), 'too short')->assertUnprocessable();
    Recovery::reset('ada@example.org', str_repeat('a', 64), Passwords::STRONG)->assertUnprocessable();
    Recovery::reset('ada@example.org', str_repeat('a', 64), Passwords::STRONG)->assertStatus(429);

    expect(Identity::context(Identity::events('authentication.rate_limited')[0]))->toMatchArray(['action' => 'password_reset_completion', 'scope' => 'identifier']);
});

it('does the same hashing work whether or not the address, the account or the token is real', function () {
    // Otherwise the endpoint's timing would say which addresses have accounts.
    $counting = new CountingHasher(app(Hasher::class));
    app()->instance(Hasher::class, $counting);
    [$ada, $adaToken] = accountWithToken('ada@example.org');
    Identity::savedActiveAccount('notoken@example.org', name: 'No Token');
    Identity::savedInvitedAccount('invited@example.org');
    Identity::savedDisabledAccount('disabled@example.org');
    Recovery::tokenFor(Identity::savedActiveAccount('later-disabled@example.org', name: 'Later'));
    $wrong = str_repeat('a', 64);

    $checksFor = function (string $email, string $token) use ($counting): int {
        // Each attempt is its own caller: reset the address limiter so none is refused.
        app('cache')->flush();
        $counting->checks = 0;
        Recovery::reset($email, $token, Passwords::STRONG);

        return $counting->checks;
    };

    expect($checksFor('nobody@example.org', $wrong))->toBe(1)
        ->and($checksFor('invited@example.org', $wrong))->toBe(1)
        ->and($checksFor('disabled@example.org', $wrong))->toBe(1)
        ->and($checksFor('notoken@example.org', $wrong))->toBe(1)
        ->and($checksFor('ada@example.org', $wrong))->toBe(1)
        ->and($checksFor('ada@example.org', $adaToken))->toBe(1);
});

it('never stores the token or the new password in plain', function () {
    [, $token] = accountWithToken();

    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertNoContent();

    expect(Faults::everythingStored())->not->toContain($token)->and(Faults::everythingStored())->not->toContain(Passwords::STRONG);
});
