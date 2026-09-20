<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\AccountRepository;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Console;
use Tests\Support\Identity;

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
});

/** A signed-in Console for a fresh active account. */
function signedIn(): Console
{
    Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    return $console;
}

/** @return list<object{type: string, outcome: string, context: ?string, subject_account_id: ?string}> */
function eventsOfType(string $type): array
{
    /** @var list<object{type: string, outcome: string, context: ?string, subject_account_id: ?string}> */
    return DB::table('security_events')->where('type', $type)->get()->all();
}

it('stores the session in the database, associated with the ULID account', function () {
    $account = Identity::savedActiveAccount();
    $console = new Console;
    $console->bootstrap();

    expect(DB::table('sessions')->count())->toBe(1)
        ->and(DB::table('sessions')->value('user_id'))->toBeNull();

    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    expect(DB::table('sessions')->count())->toBe(1)
        ->and(DB::table('sessions')->value('user_id'))->toBe($account->id->value)
        ->and(DB::table('sessions')->value('user_id'))->toHaveLength(26);
});

it('uses a 26-character string, not a bigint, for the session user id', function () {
    $type = '';
    foreach (Schema::getColumns('sessions') as $column) {
        assert(is_array($column));
        if ($column['name'] === 'user_id') {
            assert(is_string($column['type']));
            $type = $column['type'];
        }
    }

    expect($type)->toContain('26')->and($type)->not->toContain('int');
    expect(Schema::getForeignKeys('sessions'))->toBe([]);
});

it('regenerates the session id at login and destroys the pre-login session', function () {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->bootstrap();
    $before = Identity::scalar('sessions', 'id');
    $tokenBefore = $console->csrfToken();

    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    expect(DB::table('sessions')->count())->toBe(1)
        ->and(Identity::scalar('sessions', 'id'))->not->toBe($before)
        ->and(DB::table('sessions')->where('id', $before)->exists())->toBeFalse()
        // The CSRF token is regenerated too, so nothing planted before login carries over.
        ->and($console->csrfToken())->not->toBe($tokenBefore);
});

it('reports the current account to a signed-in session', function () {
    $console = signedIn();

    $console->me()->assertOk()->assertJsonPath('account.email', 'ada@example.org')->assertJsonPath('session.authenticated_at', '2026-09-19T12:00:00Z');
});

it('answers 401 to an anonymous request for the current account', function () {
    (new Console)->me()->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
});

it('has a sliding 30-minute inactivity timeout, measured in request inactivity', function () {
    expect(config('session.lifetime'))->toBe(30)->and(config('session.expire_on_close'))->toBeFalse();
    $console = signedIn();

    Console::advance(29 * 60);
    $console->me()->assertOk();          // activity refreshes last_activity...
    Console::advance(29 * 60);
    $console->me()->assertOk();          // ...so it slides
    Console::advance(31 * 60);
    $console->me()->assertUnauthorized(); // 31 minutes without a request: gone
});

it('sets authenticated_at at login, and it does not move with activity', function () {
    $console = signedIn();

    Console::advance(20 * 60);
    $console->me()->assertOk()->assertJsonPath('session.authenticated_at', '2026-09-19T12:00:00Z');
});

it('expires the session 12 hours after authentication, regardless of activity', function () {
    $console = signedIn(); // authenticated at 12:00:00; the absolute cap is 00:00:00 next day

    // The user is active throughout (a request every 25 minutes), so the 30-minute
    // inactivity rule never fires: only the absolute cap can end this session.
    $console->advanceWhileActive(12 * 3600 - 1);
    $console->me()->assertOk(); // 11h59m59s after login: still valid

    Console::advance(1);        // 12h exactly, one second after the last request
    $console->me()->assertUnauthorized();
    $console->me()->assertUnauthorized(); // and it stays over
    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
});

it('records the absolute expiry in the audit trail', function () {
    $console = signedIn();
    $account = DB::table('accounts')->first();
    $console->advanceWhileActive(12 * 3600);

    $console->me()->assertUnauthorized();

    $events = eventsOfType('session.absolute_expired');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('blocked')
        ->and($events[0]->subject_account_id)->toBe($account?->id)
        ->and(json_decode((string) $events[0]->context, true))->toBe(['reason' => 'absolute_lifetime', 'lifetime_minutes' => 720]);
});

it('does not end a session for inactivity when the cap is not reached, and does not count inactivity as expiry', function () {
    // A plain inactivity timeout is Laravel's, and is NOT an absolute expiry event.
    $console = signedIn();

    Console::advance(31 * 60);
    $console->me()->assertUnauthorized();

    expect(eventsOfType('session.absolute_expired'))->toBe([]);
});

it('starts a new absolute lifetime when the user authenticates again', function () {
    $console = signedIn();
    $console->advanceWhileActive(12 * 3600);
    $console->me()->assertUnauthorized();

    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    // Well past the ORIGINAL cap, but inside the new one: still signed in...
    $console->advanceWhileActive(12 * 3600 - 1);
    $console->me()->assertOk();
    // ...until the new cap is reached.
    Console::advance(1);
    $console->me()->assertUnauthorized();
});

it('fails safe when an authenticated session has no authenticated_at', function () {
    $console = signedIn();
    $console->tamperSession(fn (Store $session) => $session->forget('authenticated_at'));

    $console->me()->assertUnauthorized();

    $events = eventsOfType('session.absolute_expired');
    expect($events)->toHaveCount(1)
        ->and(json_decode((string) $events[0]->context, true))->toMatchArray(['reason' => 'missing_authenticated_at']);
    // ...and the session is gone rather than left usable.
    $console->me()->assertUnauthorized();
    expect(DB::table('sessions')->whereNotNull('user_id')->count())->toBe(0);
});

it('fails safe when authenticated_at is not a usable instant', function (mixed $bad) {
    $console = signedIn();
    $console->tamperSession(fn (Store $session) => $session->put('authenticated_at', $bad));

    $console->me()->assertUnauthorized();

    expect(json_decode((string) eventsOfType('session.absolute_expired')[0]->context, true))->toMatchArray(['reason' => 'invalid_authenticated_at']);
})->with([
    'a string' => ['yesterday'],
    'a float' => [1.5],
    'an array' => [[1]],
    'far in the future' => [PHP_INT_MAX],
]);

it('ends the session on logout, and the old cookie is dead', function () {
    $console = signedIn();
    $stolen = $console->cookieValues();
    $oldSessionId = Identity::scalar('sessions', 'id');

    $console->logout()->assertNoContent();

    expect($console->me()->status())->toBe(401)
        ->and(DB::table('sessions')->where('id', $oldSessionId)->exists())->toBeFalse();

    // Replaying the cookies from before logout authenticates nothing.
    Console::replaying($stolen)->me()->assertUnauthorized();
});

it('leaves the client with a fresh session and a usable CSRF token after logout', function () {
    $console = signedIn();
    $sessionBefore = Identity::scalar('sessions', 'id');
    $tokenBefore = $console->csrfToken();

    $console->logout()->assertNoContent();

    // invalidate() flushes the token, so without an explicit regeneration the client would
    // be handed an empty one and could never sign in again without a page reload.
    expect($console->csrfToken())->not->toBe('')->and($console->csrfToken())->not->toBe($tokenBefore)
        ->and(Identity::scalar('sessions', 'id'))->not->toBe($sessionBefore);
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();
});

it('leaves the client with a usable CSRF token after an absolute expiry, too', function () {
    $console = signedIn();
    $console->advanceWhileActive(12 * 3600);
    $console->me()->assertUnauthorized();

    expect($console->csrfToken())->not->toBe('');
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();
});

it('leaves the account and person unchanged by logout', function () {
    $account = Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $before = [DB::table('accounts')->first(), DB::table('people')->first()];

    $console->logout()->assertNoContent();

    expect([DB::table('accounts')->first(), DB::table('people')->first()])->toEqual($before)
        ->and(app(AccountRepository::class)->find($account->id)?->canAuthenticate())->toBeTrue();
});

it('is safe to log out with no session at all, or twice', function () {
    $console = new Console;
    $console->bootstrap();

    $console->logout()->assertNoContent();
    $console->logout()->assertNoContent();

    expect(DB::table('security_events')->where('type', 'authentication.logout')->count())->toBe(0);
});

it('stops resolving an account that is disabled mid-session', function () {
    $account = Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $console->me()->assertOk();

    app(AccountRepository::class)->save($account->disable(Identity::now()->modify('+1 day')));

    $console->me()->assertUnauthorized();
});
