<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Console;
use Tests\Support\Identity;

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
});

const UA = 'Mozilla/5.0 (X11; Linux x86_64) Test';

it('records a successful login with actor, subject, network details and no secret', function () {
    $account = Identity::savedActiveAccount();
    $console = new Console('203.0.113.9');
    $console->bootstrap();

    $console->post('/api/v1/login', ['email' => 'ada@example.org', 'password' => Identity::PASSWORD], ['User-Agent' => UA])->assertOk();

    $events = Identity::events('authentication.succeeded');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBe($account->id->value)
        ->and($events[0]->subject_account_id)->toBe($account->id->value)
        ->and($events[0]->subject_person_id)->toBe($account->personId->value)
        ->and($events[0]->ip)->toBe('203.0.113.9')
        ->and($events[0]->user_agent)->toBe(UA)
        ->and(Identity::context($events[0]))->toBe(['method' => 'password']);
});

it('records a failed login against an unknown address without fabricating any identity', function () {
    (new Console('203.0.113.9'))->login('Nobody@Example.org', 'x')->assertUnauthorized();

    $events = Identity::events('authentication.failed');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('failure')
        ->and($events[0]->actor_account_id)->toBeNull()
        ->and($events[0]->subject_account_id)->toBeNull()
        ->and($events[0]->subject_person_id)->toBeNull()
        ->and(Identity::context($events[0]))->toBe(['reason' => 'unknown_account', 'attempted_identifier' => 'nobody@example.org']);
    // Nothing was created for the stranger.
    expect(DB::table('accounts')->count())->toBe(0)->and(DB::table('people')->count())->toBe(0);
});

it('records a wrong password against a known account as the subject, never as the actor', function () {
    $account = Identity::savedActiveAccount();

    (new Console)->login('ada@example.org', 'wrong')->assertUnauthorized();

    $events = Identity::events('authentication.failed');
    expect($events[0]->actor_account_id)->toBeNull()
        ->and($events[0]->subject_account_id)->toBe($account->id->value)
        ->and($events[0]->subject_person_id)->toBe($account->personId->value)
        ->and(Identity::context($events[0])['reason'])->toBe('wrong_password');
});

it('distinguishes invited and disabled accounts in the audit trail, though never to the caller', function (string $status) {
    match ($status) {
        'invited' => Identity::savedInvitedAccount(),
        'disabled' => Identity::savedDisabledAccount(),
        default => throw new InvalidArgumentException($status),
    };

    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertUnauthorized();

    expect(Identity::context(Identity::events('authentication.failed')[0]))
        ->toBe(['reason' => 'account_not_active', 'attempted_identifier' => 'ada@example.org', 'account_status' => $status]);
})->with(['invited', 'disabled']);

it('records logout with the actor', function () {
    $account = Identity::savedActiveAccount();
    $console = new Console('203.0.113.9');
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    $console->logout()->assertNoContent();

    $events = Identity::events('authentication.logout');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBe($account->id->value)
        ->and($events[0]->subject_person_id)->toBe($account->personId->value)
        ->and($events[0]->ip)->toBe('203.0.113.9');
});

it('records each kind of security event this phase produces', function () {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', 'wrong');                        // authentication.failed
    $console->login('ada@example.org', Identity::PASSWORD);              // authentication.succeeded
    $console->logout();                                                  // authentication.logout
    $console->login('ada@example.org', Identity::PASSWORD);
    $console->advanceWhileActive(12 * 3600);
    $console->me();                                                      // session.absolute_expired
    for ($i = 0; $i < 5; $i++) {
        (new Console)->login('ada@example.org', 'wrong');
    }
    (new Console)->login('ada@example.org', Identity::PASSWORD);         // authentication.rate_limited

    $types = array_unique(array_map(fn (object $e): string => $e->type, Identity::events()));
    sort($types);

    expect($types)->toBe([
        'authentication.failed',
        'authentication.logout',
        'authentication.rate_limited',
        'authentication.succeeded',
        'session.absolute_expired',
    ]);
});

it('never records a password, token, hash, session id or CSRF value', function () {
    $password = 'Zx9!audit-marker-password';
    Identity::savedActiveAccount('ada@example.org', $password);
    $secrets = [$password, $password.'-wrong', 'not-the-password-marker'];
    $collectSessionIds = function () use (&$secrets): void {
        foreach (Identity::sessionIds() as $id) {
            $secrets[] = $id;
        }
    };

    $console = new Console('203.0.113.9');
    $console->bootstrap();
    $secrets[] = $console->csrfToken();
    $collectSessionIds();
    $console->login('ada@example.org', $password.'-wrong');
    $console->login('ada@example.org', $password)->assertOk();
    $secrets[] = $console->csrfToken();
    $collectSessionIds();
    foreach ($console->cookieValues() as $raw) {
        $secrets[] = $raw;
    }
    $console->me();
    $console->logout();
    $console->login('nobody@example.org', 'not-the-password-marker');
    $secrets[] = Identity::scalar('accounts', 'password_hash');
    for ($i = 0; $i < 6; $i++) {
        (new Console('203.0.113.9'))->login('ada@example.org', 'not-the-password-marker');
    }

    $recorded = json_encode(DB::table('security_events')->get()->all(), JSON_THROW_ON_ERROR);
    expect(count(Identity::events()))->toBeGreaterThan(5);
    foreach (array_filter($secrets, fn (string $s): bool => $s !== '') as $secret) {
        expect($recorded)->not->toContain($secret);
    }
    // Only the documented context keys ever appear.
    $keys = [];
    foreach (Identity::events() as $event) {
        $keys = array_merge($keys, array_keys(Identity::context($event)));
    }
    expect(array_unique($keys))->each->toBeIn(['method', 'reason', 'attempted_identifier', 'account_status', 'scope', 'retry_after_seconds', 'lifetime_minutes']);
});

it('records events in UTC', function () {
    Identity::savedActiveAccount();

    (new Console)->login('ada@example.org', 'wrong');

    expect(DB::table('security_events')->value('occurred_at'))->toBe('2026-09-19 12:00:00');
});
