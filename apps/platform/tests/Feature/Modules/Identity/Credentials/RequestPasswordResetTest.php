<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\IssuedPasswordReset;
use App\Modules\Identity\Application\PasswordResetNotifier;
use App\Modules\Identity\Application\RequestPasswordReset;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Infrastructure\Mail\PasswordResetMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Console;
use Tests\Support\Faults;
use Tests\Support\Identity;
use Tests\Support\Passwords;
use Tests\Support\Recovery;

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:30:00');
    Mail::fake();
});

/** @return array<string, Account> one Account in each state, by state */
function accountsInEveryState(): array
{
    $disabled = Identity::savedDisabledAccount('disabled@example.org');

    return [
        'active' => Identity::savedActiveAccount('active@example.org'),
        'invited' => Identity::savedInvitedAccount('invited@example.org'),
        'disabled' => $disabled,
    ];
}

it('emails an eligible account exactly one message', function () {
    Identity::savedActiveAccount('ada@example.org');

    Recovery::forgot('ada@example.org')->assertStatus(202);

    Mail::assertSent(PasswordResetMail::class, 1);
    Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->hasTo('ada@example.org'));
});

it('answers identically for an active, an invited, a disabled and an unknown address, and emails only the active one', function () {
    accountsInEveryState();

    $responses = [];
    foreach (['active@example.org', 'invited@example.org', 'disabled@example.org', 'nobody@example.org'] as $email) {
        $response = Recovery::forgot($email)->assertStatus(202);
        $responses[$email] = [$response->getStatusCode(), $response->getContent(), $response->headers->get('Content-Type')];
    }

    expect(array_unique($responses, SORT_REGULAR))->toHaveCount(1);
    Mail::assertSent(PasswordResetMail::class, 1);
    Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->hasTo('active@example.org'));
    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['active@example.org']);
});

it('finds the account whatever the case of the address, and keys the token by the canonical address', function () {
    Identity::savedActiveAccount('Ada.Lovelace@Example.ORG');

    Recovery::forgot('  ADA.LOVELACE@example.org ')->assertStatus(202);

    Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->hasTo('Ada.Lovelace@Example.ORG'));
    // The identifier Laravel keys the token by is the canonical email, so the same address in any case
    // is the same row on MariaDB and PostgreSQL alike.
    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['ada.lovelace@example.org']);
});

it('sends a plain-text message with the link and the lifetime, and nothing else about the account', function () {
    $account = Identity::savedActiveAccount('ada@example.org', name: 'Ada Lovelace');

    Recovery::forgot('ada@example.org')->assertStatus(202);

    $mail = Recovery::sent()[0];
    $body = Recovery::body($mail);
    $link = Recovery::linkFrom($mail);

    expect($body)->toContain('http://commons.flowlife.localhost:18080/reset-password#token='.rawurlencode($link['token']).'&email=ada%40example.org')
        ->and($body)->toContain('within 60 minutes')
        ->and($body)->not->toContain('<')
        ->and($link['email'])->toBe('ada@example.org')
        // Nothing unrelated: no identifiers, no status, no name, no hash, no client address.
        ->and($body)->not->toContain($account->id->value)
        ->and($body)->not->toContain($account->personId->value)
        ->and($body)->not->toContain('Ada Lovelace')
        ->and($body)->not->toContain('active')
        ->and($body)->not->toContain('$2y$')
        ->and($body)->not->toContain('127.0.0.1');
});

it('puts the secrets in the URL fragment, which a browser never sends to a server', function () {
    Identity::savedActiveAccount('ada@example.org');
    Recovery::forgot('ada@example.org')->assertStatus(202);

    $body = Recovery::body(Recovery::sent()[0]);
    $found = preg_match('#(https?://\S+)#', $body, $url);
    assert($found === 1);
    $link = $url[1];

    expect(parse_url($link, PHP_URL_QUERY))->toBeNull();
    expect((string) parse_url($link, PHP_URL_FRAGMENT))->toStartWith('token=');
});

it('stores only a hash of the token, and the raw token is nowhere in the database or the audit trail', function () {
    Identity::savedActiveAccount('ada@example.org');
    Recovery::forgot('ada@example.org')->assertStatus(202);
    $token = Recovery::linkFrom(Recovery::sent()[0])['token'];

    $stored = Identity::scalar('password_reset_tokens', 'token');
    expect($stored)->toStartWith('$2y$');
    expect(str_contains($stored, $token))->toBeFalse();
    expect(password_verify($token, $stored))->toBeTrue();
    expect(str_contains(Faults::everythingStored(), $token))->toBeFalse();
    foreach (Identity::events() as $event) {
        expect(str_contains((string) json_encode($event), $token))->toBeFalse();
    }
});

it('records what happened, with a reason for every request that issued nothing', function () {
    $accounts = accountsInEveryState();
    Recovery::forgot('active@example.org')->assertStatus(202);
    Recovery::forgot('invited@example.org')->assertStatus(202);
    Recovery::forgot('disabled@example.org')->assertStatus(202);
    Recovery::forgot('Nobody@Example.org')->assertStatus(202);

    $events = Identity::events('password.reset_requested');
    expect($events)->toHaveCount(4);

    [$issued, $invited, $disabled, $unknown] = $events;
    expect($issued->outcome)->toBe('success')
        ->and($issued->subject_account_id)->toBe($accounts['active']->id->value)
        ->and($issued->actor_account_id)->toBeNull()
        ->and($invited->outcome)->toBe('failure')
        ->and(Identity::context($invited))->toBe(['reason' => 'account_not_eligible', 'attempted_identifier' => 'invited@example.org'])
        ->and($invited->subject_account_id)->toBe($accounts['invited']->id->value)
        ->and(Identity::context($disabled)['reason'])->toBe('account_not_eligible')
        // An address with no account: the claimed identifier is recorded, and no identity is invented.
        ->and(Identity::context($unknown))->toBe(['reason' => 'unknown_account', 'attempted_identifier' => 'nobody@example.org'])
        ->and($unknown->subject_account_id)->toBeNull()
        ->and($unknown->subject_person_id)->toBeNull()
        ->and($unknown->actor_account_id)->toBeNull();
});

it('sends at most one message a minute to an account, still answering the same', function () {
    Identity::savedActiveAccount('ada@example.org');

    $first = Recovery::forgot('ada@example.org')->assertStatus(202);
    $hash = DB::table('password_reset_tokens')->value('token');
    $second = Recovery::forgot('ada@example.org')->assertStatus(202);

    Mail::assertSent(PasswordResetMail::class, 1);
    expect($second->getContent())->toBe($first->getContent())
        ->and(DB::table('password_reset_tokens')->value('token'))->toBe($hash)
        ->and(Identity::context(Identity::events('password.reset_requested')[1])['reason'])->toBe('recently_requested');

    // After the minute a new token replaces the old one, which stops working.
    $oldToken = Recovery::linkFrom(Recovery::sent()[0])['token'];
    Carbon::setTestNow('2026-09-19 12:31:01');
    Recovery::forgot('ada@example.org')->assertStatus(202);

    Mail::assertSent(PasswordResetMail::class, 2);
    expect(DB::table('password_reset_tokens')->count())->toBe(1)
        ->and(Recovery::reset('ada@example.org', $oldToken, Passwords::STRONG)->status())->toBe(422);
});

it('validates the address\'s shape, which says nothing about whether it has an account', function () {
    Recovery::forgot('not an address')->assertUnprocessable()->assertJsonValidationErrors(['email']);
    Recovery::forgot('')->assertUnprocessable();
    Mail::assertNothingSent();
});

it('creates no session and no cookie: the endpoint is stateless', function () {
    Identity::savedActiveAccount('ada@example.org');

    $response = Recovery::forgot('ada@example.org')->assertStatus(202);

    expect($response->baseResponse->headers->getCookies())->toBe([])
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('limits requests per identifier, answering a known and an unknown one the same way', function () {
    config(['identity.credential_throttle.password_reset_request.per_identifier' => 2]);
    Identity::savedActiveAccount('ada@example.org');

    Recovery::forgot('ada@example.org')->assertStatus(202);
    Recovery::forgot('ada@example.org')->assertStatus(202);
    $known = Recovery::forgot('ada@example.org')->assertStatus(429);

    Recovery::forgot('nobody@example.org')->assertStatus(202);
    Recovery::forgot('nobody@example.org')->assertStatus(202);
    $unknown = Recovery::forgot('nobody@example.org')->assertStatus(429);

    expect($known->getContent())->toBe($unknown->getContent())
        ->and((int) $known->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($known->headers->get('Retry-After'))->toBe($unknown->headers->get('Retry-After'));
    $events = Identity::events('authentication.rate_limited');
    expect($events)->toHaveCount(2)
        ->and(Identity::context($events[0]))->toMatchArray(['action' => 'password_reset_request', 'scope' => 'identifier']);
});

it('limits requests per source address', function () {
    config(['identity.credential_throttle.password_reset_request.per_ip' => 2]);

    Recovery::forgot('one@example.org')->assertStatus(202);
    Recovery::forgot('two@example.org')->assertStatus(202);
    Recovery::forgot('three@example.org')->assertStatus(429);

    expect(Identity::context(Identity::events('authentication.rate_limited')[0]))->toMatchArray(['scope' => 'ip']);
});

it('does not let reset requests use up the allowance for signing in or for finishing a reset', function () {
    config(['identity.credential_throttle.password_reset_request.per_identifier' => 1]);
    $account = Identity::savedActiveAccount('ada@example.org');
    $token = Recovery::tokenFor($account);

    Recovery::forgot('ada@example.org')->assertStatus(202);
    Recovery::forgot('ada@example.org')->assertStatus(429);

    // Someone flooding requests for this address can stop new requests, not the finishing of one
    // already in hand, nor a sign-in.
    Recovery::reset('ada@example.org', $token, Passwords::STRONG)->assertNoContent();
    (new Console)->login('ada@example.org', Passwords::STRONG)->assertOk();
});

it('holds every request to the response floor, so an unknown address is not answered faster', function () {
    config(['identity.password_reset.response_floor_ms' => 300]);
    Identity::savedActiveAccount('ada@example.org');

    foreach (['ada@example.org', 'nobody@example.org'] as $email) {
        $started = microtime(true);
        Recovery::forgot($email)->assertStatus(202);

        expect((microtime(true) - $started) * 1000)->toBeGreaterThanOrEqual(290, "{$email} was answered before the floor");
    }
});

it('still answers 202, and logs the failure without the link, when the message cannot be sent', function () {
    $log = Log::spy();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp is down'));
    Identity::savedActiveAccount('ada@example.org');

    Recovery::forgot('ada@example.org')->assertStatus(202);

    $log->shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context === ['exception' => RuntimeException::class, 'message' => 'smtp is down']
        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'token'));
    // The token was stored, so the owner can ask again after the minute.
    expect(DB::table('password_reset_tokens')->count())->toBe(1);
});

it('sends the message only after its own transaction has committed', function () {
    // A message is a network call. It must never hold a transaction open, and a token must never be
    // emailed for a transaction that then rolled back.
    $baseline = DB::transactionLevel();
    $depths = [];
    app()->instance(PasswordResetNotifier::class, new class($depths) implements PasswordResetNotifier
    {
        /** @param  list<int>  $depths */
        public function __construct(public array &$depths) {}

        public function send(EmailAddress $to, IssuedPasswordReset $reset): void
        {
            $this->depths[] = DB::transactionLevel();
        }
    });
    Identity::savedActiveAccount('ada@example.org');

    Recovery::forgot('ada@example.org')->assertStatus(202);

    expect($depths)->toBe([$baseline]);
});

it('rolls the token back, and sends nothing, if the audit event cannot be written', function () {
    Identity::savedActiveAccount('ada@example.org');
    Faults::auditFailsAt(1);

    expect(fn () => app(RequestPasswordReset::class)(EmailAddress::fromString('ada@example.org'), new ClientContext('127.0.0.1', 'test')))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    Mail::assertNothingSent();
});

it('keeps another account\'s token when it issues one', function () {
    $ada = Identity::savedActiveAccount('ada@example.org');
    Identity::savedActiveAccount('bob@example.org', name: 'Bob');
    $adaToken = Recovery::tokenFor($ada);

    Recovery::forgot('bob@example.org')->assertStatus(202);

    expect(DB::table('password_reset_tokens')->count())->toBe(2)
        ->and(Recovery::reset('ada@example.org', $adaToken, Passwords::STRONG)->status())->toBe(204);
});
