<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Console;
use Tests\Support\Identity;

const THROTTLED_MESSAGE = 'Too many sign-in attempts. Try again later.';

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:00:00');
});

/** Fails to sign in $times times as $email from $ip. */
function failLogins(string $email, int $times, string $ip = '203.0.113.9'): void
{
    for ($i = 0; $i < $times; $i++) {
        (new Console($ip))->login($email, 'wrong password')->assertUnauthorized();
    }
}

it('blocks an identifier after five failures, even when the next password is right', function () {
    Identity::savedActiveAccount('ada@example.org');
    failLogins('ada@example.org', 5);

    $response = (new Console('203.0.113.9'))->login('ada@example.org', Identity::PASSWORD);

    $response->assertStatus(429)->assertExactJson(['message' => THROTTLED_MESSAGE]);
    expect((int) $response->headers->get('Retry-After'))->toBeBetween(1, 900);
});

it('blocks an identifier across source addresses, defeating a distributed guess', function () {
    Identity::savedActiveAccount('ada@example.org');
    foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4', '203.0.113.5'] as $ip) {
        failLogins('ada@example.org', 1, $ip);
    }

    (new Console('198.51.100.77'))->login('ada@example.org', Identity::PASSWORD)->assertStatus(429);
});

it('blocks a source address after 30 attempts, whichever identifiers it tries', function () {
    for ($i = 1; $i <= 30; $i++) {
        (new Console('203.0.113.9'))->login("user{$i}@example.org", 'wrong')->assertUnauthorized();
    }

    (new Console('203.0.113.9'))->login('user31@example.org', 'wrong')->assertStatus(429);
    // A different address is unaffected.
    (new Console('198.51.100.1'))->login('user31@example.org', 'wrong')->assertUnauthorized();
});

it('throttles a known and an unknown identifier identically, so it reveals nothing', function () {
    Identity::savedActiveAccount('known@example.org');

    failLogins('known@example.org', 5);
    failLogins('unknown@example.org', 5);

    $known = (new Console('203.0.113.9'))->login('known@example.org', Identity::PASSWORD);
    $unknown = (new Console('203.0.113.9'))->login('unknown@example.org', Identity::PASSWORD);

    expect($known->getStatusCode())->toBe(429)->and($unknown->getStatusCode())->toBe(429)
        ->and($known->getContent())->toBe($unknown->getContent())
        ->and($known->headers->get('Retry-After'))->toBe($unknown->headers->get('Retry-After'));
});

it('counts case variants of an address as the same identifier', function () {
    Identity::savedActiveAccount('ada@example.org');
    foreach (['ada@example.org', 'ADA@example.org', 'Ada@Example.org', ' ada@example.org', 'aDa@EXAMPLE.org'] as $variant) {
        failLogins($variant, 1);
    }

    (new Console('203.0.113.9'))->login('ada@example.ORG', Identity::PASSWORD)->assertStatus(429);
});

it('lets a successful sign-in clear the identifier\'s failures', function () {
    Identity::savedActiveAccount('ada@example.org');
    failLogins('ada@example.org', 4);

    (new Console('203.0.113.9'))->login('ada@example.org', Identity::PASSWORD)->assertOk();

    failLogins('ada@example.org', 4); // would be the 5th-8th failure had the counter not been cleared
    (new Console('203.0.113.9'))->login('ada@example.org', 'wrong')->assertUnauthorized();
});

it('lifts the block once the window has passed', function () {
    Identity::savedActiveAccount('ada@example.org');
    failLogins('ada@example.org', 5);
    (new Console('203.0.113.9'))->login('ada@example.org', Identity::PASSWORD)->assertStatus(429);

    Console::advance(901);

    (new Console('203.0.113.9'))->login('ada@example.org', Identity::PASSWORD)->assertOk();
});

it('does not count a throttled attempt against the caller, or extend the block', function () {
    Identity::savedActiveAccount('ada@example.org');
    failLogins('ada@example.org', 5);

    for ($i = 0; $i < 5; $i++) {
        (new Console('203.0.113.9'))->login('ada@example.org', Identity::PASSWORD)->assertStatus(429);
    }
    Console::advance(901);

    (new Console('203.0.113.9'))->login('ada@example.org', Identity::PASSWORD)->assertOk();
});

it('is configurable', function () {
    config(['identity.login_throttle.max_failures_per_identifier' => 2]);
    Identity::savedActiveAccount('ada@example.org');
    failLogins('ada@example.org', 2);

    (new Console('203.0.113.9'))->login('ada@example.org', Identity::PASSWORD)->assertStatus(429);
});

// --- Audit -----------------------------------------------------------------------------

it('records a rate-limit event for a throttled attempt, with no secrets', function () {
    Identity::savedActiveAccount('ada@example.org');
    failLogins('ada@example.org', 5);

    (new Console('203.0.113.9'))->login('ada@example.org', Identity::PASSWORD)->assertStatus(429);

    $events = Identity::events('authentication.rate_limited');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('blocked')
        ->and($events[0]->ip)->toBe('203.0.113.9')
        // The caller is not authenticated, and no account is named: it is a claimed identifier only.
        ->and($events[0]->actor_account_id)->toBeNull()
        ->and($events[0]->subject_account_id)->toBeNull()
        ->and(array_keys(Identity::context($events[0])))->toEqualCanonicalizing(['scope', 'retry_after_seconds', 'attempted_identifier'])
        ->and(Identity::context($events[0])['scope'])->toBe('identifier')
        ->and(json_encode(DB::table('security_events')->get()->all()))->not->toContain(Identity::PASSWORD);
});

it('records the source-address limit with its own scope', function () {
    for ($i = 1; $i <= 31; $i++) {
        (new Console('203.0.113.9'))->login("user{$i}@example.org", 'wrong');
    }

    expect(Identity::context(Identity::events('authentication.rate_limited')[0])['scope'])->toBe('ip');
});

it('audits an engaged limit once per interval, so hammering it cannot grow the audit table', function () {
    Identity::savedActiveAccount('ada@example.org');
    failLogins('ada@example.org', 5);

    for ($i = 0; $i < 10; $i++) {
        (new Console('203.0.113.9'))->login('ada@example.org', Identity::PASSWORD)->assertStatus(429);
    }
    expect(Identity::events('authentication.rate_limited'))->toHaveCount(1);

    Console::advance(61); // the audit interval (60s) passes while the limit is still engaged
    (new Console('203.0.113.9'))->login('ada@example.org', Identity::PASSWORD)->assertStatus(429);

    expect(Identity::events('authentication.rate_limited'))->toHaveCount(2);
});
