<?php

declare(strict_types=1);

use App\Modules\Identity\Application\AcceptInvitation;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\InvitationToken;
use App\Shared\Domain\AccountId;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Console;
use Tests\Support\Faults;
use Tests\Support\Identity;
use Tests\Support\Passwords;

use function Pest\Laravel\postJson;

const ACCEPT_PATH = '/api/v1/invitations/accept';

beforeEach(function () {
    Carbon::setTestNow('2026-09-19 12:30:00');
    Passwords::breached();
});

/** @return array{Account, string} an invited Account and the raw token of its pending invitation */
function pendingInvitation(string $email = 'ada@example.org', ?AccountId $invitedBy = null): array
{
    $account = Identity::savedInvitedAccount($email);
    $token = InvitationToken::generate();
    app(AccountInvitationRepository::class)->save(Identity::invitation($account, $token, $invitedBy));

    return [$account, $token->reveal()];
}

/** @return TestResponse<JsonResponse> */
function acceptInvitation(string $token, string $password, ?string $confirmation = null): TestResponse
{
    return postJson(ACCEPT_PATH, ['token' => $token, 'password' => $password, 'password_confirmation' => $confirmation ?? $password]);
}

/** Everything the acceptance would have changed is still as it was. */
function expectNothingAccepted(Account $account): void
{
    $row = DB::table('accounts')->where('id', $account->id->value)->first();
    assert($row !== null);

    expect($row->password_hash)->toBeNull()
        ->and($row->password_updated_at)->toBeNull()
        ->and($row->email_verified_at)->toBeNull()
        ->and($row->status)->toBe($account->status->value)
        ->and(DB::table('account_invitations')->whereNotNull('accepted_at')->count())->toBe(0)
        ->and(Identity::events('invitation.accepted'))->toBe([]);
}

it('sets the password, activates the account and marks the invitation used, in one step', function () {
    [$account, $token] = pendingInvitation();

    acceptInvitation($token, Passwords::STRONG)->assertNoContent();

    $row = DB::table('accounts')->where('id', $account->id->value)->first();
    assert($row !== null);
    $invitation = DB::table('account_invitations')->where('account_id', $account->id->value)->first();
    assert($invitation !== null);

    $hash = $row->password_hash;
    assert(is_string($hash) && $hash !== '');
    expect($hash)->not->toContain(Passwords::STRONG);
    expect($row->status)->toBe('active')
        ->and($row->password_updated_at)->toBe('2026-09-19 12:30:00')
        ->and($row->email_verified_at)->toBe('2026-09-19 12:30:00')
        ->and($row->disabled_at)->toBeNull()
        ->and($invitation->accepted_at)->toBe('2026-09-19 12:30:00')
        ->and(app(AccountRepository::class)->find($account->id)?->canAuthenticate())->toBeTrue();
});

it('lets the new account sign in with the password it chose, through the ordinary login', function () {
    [, $token] = pendingInvitation();

    acceptInvitation($token, Passwords::STRONG)->assertNoContent();

    (new Console)->login('ada@example.org', Passwords::STRONG)->assertOk()
        ->assertJsonPath('account.email', 'ada@example.org');
    (new Console)->login('ada@example.org', Passwords::OTHER)->assertUnauthorized();
});

it('does not sign the caller in: no session, no cookie', function () {
    [, $token] = pendingInvitation();

    $response = acceptInvitation($token, Passwords::STRONG)->assertNoContent();

    expect($response->baseResponse->headers->getCookies())->toBe([])
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('records invitation.accepted for the account, without inventing an actor', function () {
    [$account, $token] = pendingInvitation();

    acceptInvitation($token, Passwords::STRONG)->assertNoContent();

    $events = Identity::events('invitation.accepted');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('success')
        ->and($events[0]->actor_account_id)->toBeNull()
        ->and($events[0]->subject_account_id)->toBe($account->id->value)
        ->and($events[0]->subject_person_id)->toBe($account->personId->value)
        ->and($events[0]->ip)->toBe('127.0.0.1');
});

it('records who vouched for the address: the platform for a bootstrap invitation, an account for an ordinary one', function () {
    [, $platformToken] = pendingInvitation('platform@example.org');
    [, $accountToken] = pendingInvitation('member@example.org', AccountId::generate());

    acceptInvitation($platformToken, Passwords::STRONG)->assertNoContent();
    acceptInvitation($accountToken, Passwords::OTHER)->assertNoContent();

    $events = Identity::events('invitation.accepted');
    expect(Identity::context($events[0]))->toBe(['issued_by' => 'platform'])
        ->and(Identity::context($events[1]))->toBe(['issued_by' => 'account']);
});

it('accepts an invitation strictly before it expires, and refuses it at the instant it does', function () {
    [$early, $earlyToken] = pendingInvitation('early@example.org');
    [$late, $lateToken] = pendingInvitation('late@example.org');

    Carbon::setTestNow(Identity::now()->modify('+7 days -1 second'));
    acceptInvitation($earlyToken, Passwords::STRONG)->assertNoContent();

    Carbon::setTestNow(Identity::now()->modify('+7 days'));
    acceptInvitation($lateToken, Passwords::STRONG)->assertUnprocessable();

    expect(app(AccountRepository::class)->find($early->id)?->canAuthenticate())->toBeTrue()
        ->and(DB::table('accounts')->where('id', $late->id->value)->value('password_hash'))->toBeNull();
});

it('gives ONE answer for an invitation that cannot be used, whatever the reason', function () {
    // Unknown, malformed, expired, already used, revoked (deleted), and one whose account is disabled
    // or already active: a caller cannot tell them apart, so the response cannot be used to probe.
    [, $used] = pendingInvitation('used@example.org');
    acceptInvitation($used, Passwords::STRONG)->assertNoContent();

    [, $expired] = pendingInvitation('expired@example.org');
    [, $revoked] = pendingInvitation('revoked@example.org');
    DB::table('account_invitations')->whereIn('account_id', DB::table('accounts')->where('email_canonical', 'revoked@example.org')->select('id'))->delete();

    [$disabledAccount, $disabled] = pendingInvitation('disabled@example.org');
    app(DisableAccount::class)($disabledAccount->id);

    $activeAccount = Identity::savedActiveAccount('active@example.org');
    $active = InvitationToken::generate();
    app(AccountInvitationRepository::class)->save(Identity::invitation($activeAccount, $active));

    $cases = [
        'unknown' => InvitationToken::generate()->reveal(),
        'malformed' => 'not-a-token',
        'too short' => str_repeat('a', 42),
        'wrong alphabet' => str_repeat('!', 43),
        'already used' => $used,
        'revoked' => $revoked,
        'account disabled' => $disabled,
        'account already active' => $active->reveal(),
    ];
    $bodies = [];
    foreach ($cases as $why => $token) {
        $response = acceptInvitation($token, Passwords::OTHER)->assertUnprocessable();
        $bodies[$why] = $response->getContent();
    }

    Carbon::setTestNow(Identity::now()->modify('+8 days'));
    $bodies['expired'] = acceptInvitation($expired, Passwords::OTHER)->assertUnprocessable()->getContent();

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(json_decode((string) reset($bodies), true))->toHaveKey('errors.token');
});

it('leaves a used invitation used, and its password unchanged, when it is presented again', function () {
    [$account, $token] = pendingInvitation();
    acceptInvitation($token, Passwords::STRONG)->assertNoContent();
    $before = DB::table('accounts')->where('id', $account->id->value)->value('password_hash');

    acceptInvitation($token, Passwords::OTHER)->assertUnprocessable();

    expect(DB::table('accounts')->where('id', $account->id->value)->value('password_hash'))->toBe($before)
        ->and(Identity::events('invitation.accepted'))->toHaveCount(1);
    (new Console)->login('ada@example.org', Passwords::STRONG)->assertOk();
});

it('does not bring a disabled account back to life', function () {
    [$account, $token] = pendingInvitation();
    app(DisableAccount::class)($account->id);

    acceptInvitation($token, Passwords::STRONG)->assertUnprocessable();

    $row = DB::table('accounts')->where('id', $account->id->value)->first();
    expect($row?->status)->toBe('disabled')
        ->and($row?->password_hash)->toBeNull()
        ->and(DB::table('account_invitations')->whereNotNull('accepted_at')->count())->toBe(0);
});

it('refuses a weak password with the same answer whatever the token is, and uses nothing up', function () {
    [$account, $token] = pendingInvitation();

    $real = acceptInvitation($token, 'too short')->assertUnprocessable();
    $unknown = acceptInvitation(InvitationToken::generate()->reveal(), 'too short')->assertUnprocessable();

    // A rejected password reveals nothing about whether the token was any good.
    expect($real->getContent())->toBe($unknown->getContent())
        ->and($real->json('errors.password.0'))->toContain('at least 15');
    expectNothingAccepted($account);
    acceptInvitation($token, Passwords::STRONG)->assertNoContent(); // still usable afterwards
});

it('says why a password was refused, per reason', function () {
    [, $token] = pendingInvitation();

    acceptInvitation($token, str_repeat('a', 73))->assertUnprocessable()
        ->assertJsonPath('errors.password.0', fn (string $m): bool => str_contains($m, '72 bytes'));
    acceptInvitation($token, "has a nul \0 inside of it")->assertUnprocessable()
        ->assertJsonPath('errors.password.0', fn (string $m): bool => str_contains($m, 'null'));
});

it('refuses a compromised password and leaves the invitation usable', function () {
    Passwords::breached('correct horse battery staple');
    [$account, $token] = pendingInvitation();

    acceptInvitation($token, 'correct horse battery staple')->assertUnprocessable()
        ->assertJsonPath('errors.password.0', fn (string $m): bool => str_contains($m, 'data breaches'));

    expectNothingAccepted($account);
});

it('does NOT accept the password when the breach check is down: a retryable 503, nothing changed', function () {
    Passwords::checkerDown();
    [$account, $token] = pendingInvitation();

    $response = acceptInvitation($token, Passwords::STRONG)->assertStatus(503);

    expect($response->headers->get('Retry-After'))->toBe('30');
    expectNothingAccepted($account);

    Passwords::breached(); // the service is back
    acceptInvitation($token, Passwords::STRONG)->assertNoContent();
});

it('runs the breach check before its own transaction, never inside it', function () {
    // The check is a network call in production. It must not hold a database transaction open.
    $fake = Passwords::breached();
    [, $token] = pendingInvitation();
    $depthBefore = DB::transactionLevel();

    acceptInvitation($token, Passwords::STRONG)->assertNoContent();

    expect($fake->depths)->toBe([$depthBefore]);
});

it('asks the breach service nothing about a request that cannot succeed anyway', function () {
    $fake = Passwords::breached();
    [, $token] = pendingInvitation();

    acceptInvitation('not-a-token', Passwords::STRONG)->assertUnprocessable(); // malformed token
    acceptInvitation($token, 'too short')->assertUnprocessable();               // fails the offline rules

    expect($fake->checked)->toBe([]);
});

it('requires the confirmation to match, comparing what would be hashed', function () {
    [$account, $token] = pendingInvitation();

    acceptInvitation($token, Passwords::STRONG, Passwords::OTHER)->assertUnprocessable()
        ->assertJsonValidationErrors(['password_confirmation']);
    expectNothingAccepted($account);

    // The same text spelled with combining accents is the same password, so it confirms.
    $precomposed = 'crème brûlée à la façon';
    acceptInvitation($token, $precomposed, Passwords::decomposed($precomposed))->assertNoContent();
    (new Console)->login('ada@example.org', Passwords::decomposed($precomposed))->assertOk();
});

it('validates the shape of the request', function (array $body) {
    postJson(ACCEPT_PATH, $body)->assertUnprocessable();
})->with([
    'nothing' => [[]],
    'no token' => [['password' => Passwords::STRONG, 'password_confirmation' => Passwords::STRONG]],
    'no password' => [['token' => 'x', 'password_confirmation' => 'x']],
    'no confirmation' => [['token' => 'x', 'password' => Passwords::STRONG]],
    'array password' => [['token' => 'x', 'password' => ['a'], 'password_confirmation' => ['a']]],
]);

it('keeps a password exactly as typed: spaces at either end are part of it', function () {
    // The framework trims request strings, but not these fields, and a password must never be altered
    // in transit. This pins both that default and that nothing of ours trims.
    [, $token] = pendingInvitation();
    $typed = '  a passphrase with edge spaces  ';

    acceptInvitation($token, $typed)->assertNoContent();

    (new Console)->login('ada@example.org', trim($typed))->assertUnauthorized();
    (new Console)->login('ada@example.org', $typed)->assertOk();
});

it('accepts a Unicode password whichever way its accents are spelled', function () {
    [, $token] = pendingInvitation();
    $precomposed = 'ünïcödé passphrase ✓';

    acceptInvitation($token, Passwords::decomposed($precomposed))->assertNoContent();

    (new Console)->login('ada@example.org', $precomposed)->assertOk();
});

it('accepts a password of exactly 72 bytes and refuses one of 73', function () {
    [, $token] = pendingInvitation();

    acceptInvitation($token, str_repeat('a', 73))->assertUnprocessable();
    acceptInvitation($token, str_repeat('a', 72))->assertNoContent();

    (new Console)->login('ada@example.org', str_repeat('a', 72))->assertOk();
    // Not the 73-byte lookalike: bcrypt alone would have accepted it.
    (new Console)->login('ada@example.org', str_repeat('a', 73))->assertUnauthorized();
});

it('rolls everything back if the audit event cannot be written', function () {
    [$account, $token] = pendingInvitation();
    Faults::auditFailsAt(1);

    expect(fn () => app(AcceptInvitation::class)($token, Passwords::STRONG, new ClientContext('127.0.0.1', 'test')))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expectNothingAccepted($account);
});

it('rolls the activation back if the invitation cannot be marked used', function () {
    [$account, $token] = pendingInvitation();
    Faults::invitationSaveFails();

    expect(fn () => app(AcceptInvitation::class)($token, Passwords::STRONG, new ClientContext('127.0.0.1', 'test')))
        ->toThrow(RuntimeException::class, 'invitation write failed');

    expectNothingAccepted($account);
});

it('rate limits acceptance by source address, and records the limit being engaged once', function () {
    config(['identity.credential_throttle.invitation_acceptance.per_ip' => 2]);

    acceptInvitation(InvitationToken::generate()->reveal(), Passwords::STRONG)->assertUnprocessable();
    acceptInvitation(InvitationToken::generate()->reveal(), Passwords::STRONG)->assertUnprocessable();
    $blocked = acceptInvitation(InvitationToken::generate()->reveal(), Passwords::STRONG)->assertStatus(429);
    acceptInvitation(InvitationToken::generate()->reveal(), Passwords::STRONG)->assertStatus(429);

    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0);
    $events = Identity::events('authentication.rate_limited');
    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('blocked')
        ->and(Identity::context($events[0]))->toMatchArray(['action' => 'invitation_acceptance', 'scope' => 'ip']);
});

it('never stores, logs or records the token or the password in plain', function () {
    [, $token] = pendingInvitation();
    acceptInvitation($token, Passwords::STRONG)->assertNoContent();

    $everything = Faults::everythingStored();
    expect($everything)->not->toContain($token)
        ->and($everything)->not->toContain(Passwords::STRONG)
        // Only the hash of the token is kept, and it is not the token.
        ->and(DB::table('account_invitations')->value('token_hash'))->toBe(hash('sha256', $token));

    foreach (Identity::events() as $event) {
        expect(json_encode($event))->not->toContain($token)->and(json_encode($event))->not->toContain('$2y$');
    }
});

it('does not put the token in a URL: the route has no path parameter', function () {
    expect(route('api.v1.invitations.accept', [], false))->toBe('/api/v1/invitations/accept');
});
