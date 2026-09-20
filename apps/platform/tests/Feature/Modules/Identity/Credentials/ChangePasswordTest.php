<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ChangePassword;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\NoLongerAuthenticated;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Console;
use Tests\Support\Faults;
use Tests\Support\Identity;
use Tests\Support\Passwords;

const CHANGE_PATH = '/api/v1/password/change';

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
    Passwords::breached();
});

/** A Console signed in as a fresh active account (ada@example.org, Identity::PASSWORD). */
function signedInAsAda(): Console
{
    Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    return $console;
}

/** @return TestResponse<Response> */
function changePasswordAs(Console $console, string $current, string $new, ?string $confirmation = null): TestResponse
{
    return $console->post(CHANGE_PATH, ['current_password' => $current, 'password' => $new, 'password_confirmation' => $confirmation ?? $new]);
}

/** @return list<string> the ids of the sessions belonging to the account, sorted */
function sessionsOf(Account $account): array
{
    $ids = array_map(fn (mixed $id): string => is_string($id) ? $id : '', DB::table('sessions')->where('user_id', $account->id->value)->pluck('id')->all());
    sort($ids);

    return $ids;
}

function adaAccount(): Account
{
    $account = app(AccountRepository::class)->findByEmail(EmailAddress::fromString('ada@example.org'));
    assert($account !== null);

    return $account;
}

/** Nothing about the account, its sessions or the audit trail changed. */
function expectPasswordChangeChangedNothing(Account $before, Console $console, string $sessionBefore): void
{
    expect(DB::table('accounts')->where('id', $before->id->value)->value('password_hash'))->toBe($before->passwordHash)
        ->and(Identity::events('password.changed'))->toBe([])
        ->and(sessionsOf($before))->toContain($sessionBefore);
    $console->me()->assertOk();
}

it('requires a signed-in account', function () {
    Identity::savedActiveAccount();
    $anonymous = new Console;
    $anonymous->bootstrap();

    changePasswordAs($anonymous, Identity::PASSWORD, Passwords::STRONG)->assertUnauthorized();

    $anonymous->login('ada@example.org', Identity::PASSWORD)->assertOk(); // the password is unchanged
});

it('is a stateful, CSRF-protected endpoint like logout', function () {
    $console = signedInAsAda();

    $console->post(CHANGE_PATH, ['current_password' => Identity::PASSWORD, 'password' => Passwords::STRONG, 'password_confirmation' => Passwords::STRONG], [], withXsrfHeader: false)
        ->assertStatus(419);

    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertOk();
});

it('changes the password: the new one signs in and the old one no longer does', function () {
    $console = signedInAsAda();

    changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertNoContent();

    (new Console)->login('ada@example.org', Passwords::STRONG)->assertOk();
    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertUnauthorized();
    expect(DB::table('accounts')->value('password_updated_at'))->toBe('2026-09-19 12:00:00');
});

it('requires the CURRENT password: a session alone cannot change it, and a wrong one changes nothing', function () {
    $console = signedInAsAda();
    $other = new Console;
    $other->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $account = adaAccount();
    $sessionBefore = sessionsOf($account);
    Console::advance(600); // ten minutes on: time has passed, so a re-dated session would show it
    $authenticatedAt = $console->me()->json('session.authenticated_at');

    changePasswordAs($console, 'not the current password', Passwords::STRONG)->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');

    expect(sessionsOf($account))->toBe($sessionBefore)
        // The session is exactly as it was: not rotated, not re-dated.
        ->and($console->me()->json('session.authenticated_at'))->toBe($authenticatedAt)
        ->and(DB::table('accounts')->value('password_hash'))->toBe($account->passwordHash)
        ->and(Identity::events('password.changed'))->toBe([]);
    $other->me()->assertOk();
    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertOk();
});

it('requires the current password to be present', function () {
    $console = signedInAsAda();

    $console->post(CHANGE_PATH, ['password' => Passwords::STRONG, 'password_confirmation' => Passwords::STRONG])
        ->assertUnprocessable()->assertJsonValidationErrors(['current_password']);
});

it('verifies the current password against the credential as it is NOW, not as the session began', function () {
    // Someone reset this account's password after the session was created. The session's own
    // password no longer proves anything.
    $console = signedInAsAda();
    DB::table('accounts')->update(['password_hash' => Hash::make('the password set by a reset')]);

    changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'The current password is incorrect.');
    changePasswordAs($console, 'the password set by a reset', Passwords::STRONG)->assertNoContent();
});

it('ends every OTHER session of the account, and nobody else\'s', function () {
    $console = signedInAsAda();
    $laptop = new Console;
    $phone = new Console;
    $laptop->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $phone->login('ada@example.org', Identity::PASSWORD)->assertOk();
    Identity::savedActiveAccount('bob@example.org', name: 'Bob');
    $bob = new Console;
    $bob->login('bob@example.org', Identity::PASSWORD)->assertOk();
    (new Console)->bootstrap();
    expect(sessionsOf(adaAccount()))->toHaveCount(3);

    changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertNoContent();

    $laptop->me()->assertUnauthorized();
    $phone->me()->assertUnauthorized();
    $bob->me()->assertOk();
    $console->me()->assertOk();
    // Ada's one and Bob's. (The ended sessions' next requests started fresh anonymous ones, as any
    // first request does, so the table's total is not the point.)
    expect(sessionsOf(adaAccount()))->toHaveCount(1)
        ->and(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(2);
    expect(Identity::context(Identity::events('password.changed')[0]))->toBe(['signed_out' => 2]);
});

it('keeps the current session alive but ROTATES it: a new id and CSRF token, and the old id is dead', function () {
    $console = signedInAsAda();
    $account = adaAccount();
    [$oldId] = sessionsOf($account);
    $oldCookies = $console->cookieValues();
    $oldToken = $console->csrfToken();

    changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertNoContent();

    $ids = sessionsOf($account);
    expect($ids)->toHaveCount(1)
        ->and($ids[0])->not->toBe($oldId)
        ->and(DB::table('sessions')->where('id', $oldId)->exists())->toBeFalse()
        ->and($console->csrfToken())->not->toBe($oldToken);
    // The current browser carries on without signing in again...
    $console->me()->assertOk()->assertJsonPath('account.email', 'ada@example.org');
    // ...but the id it had before is worthless to anyone who copied it.
    Console::replaying($oldCookies)->me()->assertUnauthorized();
});

it('restarts the 12-hour authentication instant, because the current password was just proved', function () {
    $console = signedInAsAda();
    $console->advanceWhileActive(10 * 3600);
    $before = $console->me()->assertOk()->json('session.authenticated_at');

    changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertNoContent();

    $after = $console->me()->assertOk();
    expect($after->json('session.authenticated_at'))->not->toBe($before)
        ->and($after->json('session.authenticated_at'))->toBe(Carbon::now()->toIso8601ZuluString())
        ->and($after->json('session.absolute_expires_at'))->toBe(Carbon::now()->addHours(12)->toIso8601ZuluString());

    // Eleven hours on is 21 hours after the original sign-in, well past the old 12-hour cap.
    $console->advanceWhileActive(11 * 3600);
    $console->me()->assertOk();
    // And the new lifetime is still a lifetime: it ends 12 hours after the change.
    $console->advanceWhileActive(3600 + 1);
    $console->me()->assertUnauthorized();
});

it('does NOT restart the instant when the current password is wrong', function () {
    $console = signedInAsAda();
    $console->advanceWhileActive(11 * 3600);

    changePasswordAs($console, 'wrong wrong wrong wrong', Passwords::STRONG)->assertUnprocessable();

    // Without a fresh proof, the original 12 hours still run out.
    $console->advanceWhileActive(3600 + 1);
    $console->me()->assertUnauthorized();
});

it('records password.changed with the account as both actor and subject', function () {
    $console = signedInAsAda();
    $account = adaAccount();

    changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertNoContent();

    $events = Identity::events('password.changed');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBe($account->id->value)
        ->and($events[0]->subject_account_id)->toBe($account->id->value)
        ->and($events[0]->subject_person_id)->toBe($account->personId->value)
        ->and($events[0]->ip)->toBe('127.0.0.1');
});

it('holds the new password to the same policy, and a refused password changes nothing, session included', function () {
    $console = signedInAsAda();
    $account = adaAccount();
    [$sessionBefore] = sessionsOf($account);
    Passwords::breached('correct horse battery staple');

    changePasswordAs($console, Identity::PASSWORD, 'too short')->assertUnprocessable()
        ->assertJsonPath('errors.password.0', fn (string $m): bool => str_contains($m, 'at least 15'));
    changePasswordAs($console, Identity::PASSWORD, str_repeat('a', 73))->assertUnprocessable();
    changePasswordAs($console, Identity::PASSWORD, 'correct horse battery staple')->assertUnprocessable()
        ->assertJsonPath('errors.password.0', fn (string $m): bool => str_contains($m, 'data breaches'));
    changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG, Passwords::OTHER)->assertUnprocessable()
        ->assertJsonValidationErrors(['password_confirmation']);

    expectPasswordChangeChangedNothing($account, $console, $sessionBefore);
});

it('does NOT accept the new password when the breach check is down: a retryable 503, nothing changed', function () {
    $console = signedInAsAda();
    $account = adaAccount();
    [$sessionBefore] = sessionsOf($account);
    Passwords::checkerDown();

    $response = changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertStatus(503);

    expect($response->headers->get('Retry-After'))->toBe('30');
    expectPasswordChangeChangedNothing($account, $console, $sessionBefore);
});

it('runs the breach check before its own transaction, never inside it', function () {
    $console = signedInAsAda();
    $fake = Passwords::breached();
    $baseline = DB::transactionLevel();

    changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertNoContent();

    expect($fake->depths)->toBe([$baseline]);
});

it('keeps the new password exactly as typed, whichever way its accents are spelled', function () {
    $console = signedInAsAda();
    $typed = '  ünïcödé passphrase ✓  ';

    changePasswordAs($console, Identity::PASSWORD, Passwords::decomposed($typed))->assertNoContent();

    (new Console)->login('ada@example.org', $typed)->assertOk();
    (new Console)->login('ada@example.org', trim($typed))->assertUnauthorized();
});

it('accepts the current password however it is spelled, like every other path', function () {
    Identity::savedActiveAccount(password: 'crème brûlée à la façon');
    $console = new Console;
    $console->login('ada@example.org', Passwords::decomposed('crème brûlée à la façon'))->assertOk();

    changePasswordAs($console, Passwords::decomposed('crème brûlée à la façon'), Passwords::STRONG)->assertNoContent();
});

it('rolls everything back if the audit event cannot be written: password and the other sessions', function () {
    $console = signedInAsAda();
    $other = new Console;
    $other->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $account = adaAccount();
    $sessions = sessionsOf($account);
    $actor = Actor::user($account->id, $account->personId);
    Faults::auditFailsAt(1);

    expect(fn () => app(ChangePassword::class)($actor, Identity::PASSWORD, Passwords::STRONG, $sessions[0], new ClientContext('127.0.0.1', 'test')))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(DB::table('accounts')->value('password_hash'))->toBe($account->passwordHash)
        ->and(sessionsOf($account))->toBe($sessions)
        ->and(Identity::events('password.changed'))->toBe([]);
    $console->me()->assertOk();
    $other->me()->assertOk();
});

it('rolls everything back if the new password cannot be saved', function () {
    signedInAsAda();
    $account = adaAccount();
    $sessions = sessionsOf($account);
    Faults::accountSaveFails();

    expect(fn () => app(ChangePassword::class)(Actor::user($account->id, $account->personId), Identity::PASSWORD, Passwords::STRONG, 'x', new ClientContext('127.0.0.1', 'test')))
        ->toThrow(RuntimeException::class, 'account write failed');

    expect(sessionsOf($account))->toBe($sessions);
});

it('treats an account disabled while the change was in flight as no longer signed in, and changes nothing', function () {
    signedInAsAda();
    $account = adaAccount();
    $actor = Actor::user($account->id, $account->personId);
    $change = app(ChangePassword::class);
    app(DisableAccount::class)($account->id);

    expect(fn () => $change($actor, Identity::PASSWORD, Passwords::STRONG, 'x', new ClientContext('127.0.0.1', 'test')))
        ->toThrow(NoLongerAuthenticated::class);

    expect(DB::table('accounts')->value('status'))->toBe('disabled')
        ->and(DB::table('accounts')->value('password_hash'))->toBe($account->passwordHash)
        ->and(Identity::events('password.changed'))->toBe([]);
});

it('refuses an Actor whose person is not the account\'s, as the Authorizer does', function () {
    signedInAsAda();
    $account = adaAccount();
    $forged = Actor::user($account->id, PersonId::generate());

    expect(fn () => app(ChangePassword::class)($forged, Identity::PASSWORD, Passwords::STRONG, 'x', new ClientContext('127.0.0.1', 'test')))
        ->toThrow(NoLongerAuthenticated::class);

    expect(DB::table('accounts')->value('password_hash'))->toBe($account->passwordHash);
});

it('limits guesses at the current password per account, so a stolen session cannot try passwords at will', function () {
    config(['identity.credential_throttle.password_change.per_identifier' => 3]);
    $console = signedInAsAda();
    $account = adaAccount();

    foreach (range(1, 3) as $attempt) {
        changePasswordAs($console, "wrong guess number {$attempt}!", Passwords::STRONG)->assertUnprocessable();
    }
    // The next attempt is refused before the password is looked at, even with the RIGHT one.
    $blocked = changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertStatus(429);

    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(DB::table('accounts')->value('password_hash'))->toBe($account->passwordHash);
    $events = Identity::events('authentication.rate_limited');
    expect($events)->toHaveCount(1)
        ->and(Identity::context($events[0]))->toMatchArray(['action' => 'password_change', 'scope' => 'identifier']);

    // Another account's allowance is its own.
    Identity::savedActiveAccount('bob@example.org', name: 'Bob');
    $bob = new Console;
    $bob->login('bob@example.org', Identity::PASSWORD)->assertOk();
    changePasswordAs($bob, Identity::PASSWORD, Passwords::STRONG)->assertNoContent();
});

it('issues the rotated session cookie with the same fixed attributes as at sign-in', function () {
    $console = signedInAsAda();

    $response = changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertNoContent();

    $session = collect($response->baseResponse->headers->getCookies())->first(fn ($c): bool => $c->getName() === Console::SESSION_COOKIE);
    expect($session)->not->toBeNull()
        ->and($session?->isSecure())->toBeTrue()
        ->and($session?->isHttpOnly())->toBeTrue()
        ->and($session?->getPath())->toBe('/')
        ->and($session?->getDomain())->toBeNull()
        ->and($session?->getSameSite())->toBe('lax');
});

it('never stores or records either password in plain', function () {
    $console = signedInAsAda();

    changePasswordAs($console, Identity::PASSWORD, Passwords::STRONG)->assertNoContent();

    expect(str_contains(Faults::everythingStored(), Passwords::STRONG))->toBeFalse();
    expect(str_contains(Faults::everythingStored(), Identity::PASSWORD))->toBeFalse();
});
