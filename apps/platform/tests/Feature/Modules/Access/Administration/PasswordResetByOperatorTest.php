<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\SendManagedPasswordReset;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\IssueAdministrativePasswordReset;
use App\Modules\Identity\Application\IssuedPasswordReset;
use App\Modules\Identity\Application\PasswordResetDelivery;
use App\Modules\Identity\Application\PasswordResetNotifier;
use App\Modules\Identity\Application\PasswordResetTokens;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Infrastructure\Mail\PasswordResetMail;
use App\Shared\Domain\AccountId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Faults;
use Tests\Support\Identity;
use Tests\Support\Mfa;
use Tests\Support\Recovery;
use Tests\Support\Totp;

/*
 * An operator sends an Account holder the NORMAL password-reset email (ADR 0024). The operator never chooses, sees or
 * receives the password, the token or the link; the holder follows the usual link and sets their own password. It is
 * the existing reset issuance and message, behind `identity.accounts.manage` and a recent proof, and it is audited.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 12:00:00');
    Mail::fake();
});

const RESET_PATH = '/api/v1/admin/accounts/%s/password-reset';

function resetPath(string $accountId): string
{
    return sprintf(RESET_PATH, $accountId);
}

it('sends the Account holder the normal reset email, and nothing else: no token, link or password reaches the operator', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org', name: 'Holder');
    $before = DB::table('accounts')->where('id', $target->id->value)->first(['password_hash', 'status', 'password_updated_at']);

    $response = $console->post(resetPath($target->id->value))->assertOk();

    Mail::assertSent(PasswordResetMail::class, 1);
    Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail): bool => $mail->hasTo('holder@example.org'));
    $link = Recovery::linkFrom(Recovery::sent()[0]);
    $events = Identity::events('password.reset_requested_by_operator');

    expect(array_keys(Api::map($response->json())))->toBe(['account', 'delivery']) // exactly the documented keys, so nothing else can ride along
        ->and($response->json('delivery'))->toBe(['status' => 'sent'])
        ->and($response->json('account.id'))->toBe($target->id->value)
        ->and($response->json('status'))->toBeNull() // the Account is returned under `account`, with no new top-level state
        ->and(DB::table('password_reset_tokens')->count())->toBe(1)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and($events[0]->subject_account_id)->toBe($target->id->value)
        ->and($events[0]->outcome)->toBe('success')
        // Nothing about the Account changed: the password is the holder's to set, by completing the reset.
        ->and(DB::table('accounts')->where('id', $target->id->value)->first(['password_hash', 'status', 'password_updated_at']))->toEqual($before);

    // The secret reached the holder's address and nowhere else.
    Mfa::assertAbsent($response->getContent().Mfa::auditText(), $link['token'], '#token', 'password_reset', 'reset_url');
});

it('uses the existing flow end to end: the emailed link completes a reset exactly as an ordinary request\'s would', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org');
    $console->post(resetPath($target->id->value))->assertOk();
    $link = Recovery::linkFrom(Recovery::sent()[0]);

    Recovery::reset('holder@example.org', $link['token'], 'a brand new passphrase for holder')->assertSuccessful();

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    (new Console)->login('holder@example.org', 'a brand new passphrase for holder')->assertSuccessful();
});

it('says nothing more than the Account holder would get from "I forgot my password": the same message', function () {
    [$console] = Mfa::signedInAdmin();
    Identity::savedActiveAccount('ordinary@example.org');
    $target = Identity::savedActiveAccount('holder@example.org');
    Recovery::forgot('ordinary@example.org')->assertStatus(202);
    $console->post(resetPath($target->id->value))->assertOk();

    [$ordinary, $operator] = Recovery::sent();
    $normalise = fn (PasswordResetMail $mail): string => preg_replace('/#token=\S+/', '#token=X', Recovery::body($mail)) ?? '';

    expect($normalise($operator))->toBe($normalise($ordinary))
        ->and($operator->envelope()->subject)->toBe($ordinary->envelope()->subject);
});

it('changes nothing else about the Account: sessions, second factor and roles stand until the holder completes a reset', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Mfa::guardian('holder@example.org');
    Mfa::enroll($target, Totp::RFC_SECRET);
    $holder = new Console;
    $holder->loginWithMfa('holder@example.org', Identity::PASSWORD, Totp::RFC_SECRET)->assertOk();

    $console->post(resetPath($target->id->value))->assertOk();

    $holder->me()->assertOk(); // not signed out: this sends an email, it does not end anything
    expect(Mfa::isEnrolled($target))->toBeTrue()
        ->and(Identity::events('mfa.administratively_reset'))->toBe([])
        ->and(DB::table('role_assignments')->where('person_id', $target->personId->value)->count())->toBe(1);
});

it('needs a fresh proof: a stale one is refused and nothing is issued, sent or recorded', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org');
    Console::advance(16 * 60);

    $console->post(resetPath($target->id->value))->assertForbidden()->assertJson(['verification_required' => true]);

    Mail::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0)->and(Identity::events('password.reset_requested_by_operator'))->toBe([]);
});

it('refuses an Account that may use the Console but not administer it, before asking for any proof', function () {
    [$console] = Mfa::signedIn('guardian@example.org', Totp::RFC_SECRET);
    $target = Identity::savedActiveAccount('holder@example.org');

    $response = $console->post(resetPath($target->id->value))->assertForbidden();

    expect($response->json('verification_required'))->toBeNull();
    Mail::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('refuses at the use case alone: an actor without identity.accounts.manage, whatever the route said', function () {
    $actor = Access::actorFor(Identity::savedActiveAccount('plain@example.org'));
    $target = Identity::savedActiveAccount('holder@example.org');

    expect(fn () => app(SendManagedPasswordReset::class)($actor, $target->id, new ClientContext('127.0.0.1', 'test')))->toThrow(AccessDenied::class);
    Mail::assertNothingSent();
});

it('answers an unknown Account with a stable 404, and a malformed id with no route at all', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post(resetPath(AccountId::generate()->value))->assertNotFound()->assertJson(['code' => 'account_not_found']);
    $console->post(resetPath('not-an-id'))->assertNotFound();

    Mail::assertNothingSent();
});

it('does not become a second invitation mechanism: an INVITED Account is told to be sent an invitation instead', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedInvitedAccount('invited@example.org');

    $console->post(resetPath($target->id->value))->assertStatus(409)->assertJson(['code' => 'password_reset_account_invited']);

    Mail::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0)->and(Identity::events('password.reset_requested_by_operator'))->toBe([]);
});

it('does not recover a DISABLED Account', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedDisabledAccount('disabled@example.org');

    $console->post(resetPath($target->id->value))->assertStatus(409)->assertJson(['code' => 'password_reset_account_disabled']);

    Mail::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('applies the public flow\'s one-a-minute rule, so it cannot flood an inbox, and the public answer is unchanged', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org');
    $console->post(resetPath($target->id->value))->assertOk();

    $console->post(resetPath($target->id->value))->assertStatus(409)->assertJson(['code' => 'password_reset_recently_requested']);
    Recovery::forgot('holder@example.org')->assertStatus(202); // anti-enumeration: identical answer, and no second message

    Mail::assertSent(PasswordResetMail::class, 1);
    expect(Identity::events('password.reset_requested_by_operator'))->toHaveCount(1);

    Console::advance(61);
    $console->post(resetPath($target->id->value))->assertOk();
    Mail::assertSent(PasswordResetMail::class, 2);
});

it('replaces an earlier token, as any new request does: only the latest is live', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org');
    $old = Recovery::tokenFor($target);
    Console::advance(61);

    $console->post(resetPath($target->id->value))->assertOk();
    $new = Recovery::linkFrom(Recovery::sent()[0])['token'];

    $tokens = app(PasswordResetTokens::class);
    expect($tokens->isValid($target, $old))->toBeFalse()->and($tokens->isValid($target, $new))->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(1);
});

it('reports a delivery failure truthfully, records the fact and never the token, and leaves the Account as it was', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org');
    $issued = [];
    app()->instance(PasswordResetNotifier::class, new class($issued) implements PasswordResetNotifier
    {
        /** @param  list<string>  $issued */
        public function __construct(public array &$issued) {}

        public function send(EmailAddress $to, IssuedPasswordReset $reset): PasswordResetDelivery
        {
            $this->issued[] = $reset->revealToken();

            return PasswordResetDelivery::Failed;
        }
    });

    $response = $console->post(resetPath($target->id->value))->assertOk();

    $failures = Identity::events('password.reset_delivery_failed');
    expect($response->json('delivery'))->toBe(['status' => 'failed'])
        ->and($failures)->toHaveCount(1)
        ->and($failures[0]->outcome)->toBe('failure')
        ->and($failures[0]->actor_account_id)->toBe($admin->id->value)
        ->and(Identity::events('password.reset_requested_by_operator'))->toHaveCount(1)
        ->and(DB::table('password_reset_tokens')->count())->toBe(1);
    Mfa::assertAbsent($response->getContent().Mfa::auditText(), $issued[0]);
});

it('keeps the token out of the log when the mail system itself fails', function () {
    $log = Log::spy();
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp is down'));
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org');

    $response = $console->post(resetPath($target->id->value))->assertOk();

    expect($response->json('delivery'))->toBe(['status' => 'failed']);
    $log->shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context === ['exception' => RuntimeException::class, 'message' => 'smtp is down']);
});

it('sends the message only after its own transaction has committed, never inside one', function () {
    $baseline = DB::transactionLevel();
    $depths = [];
    app()->instance(PasswordResetNotifier::class, new class($depths) implements PasswordResetNotifier
    {
        /** @param  list<int>  $depths */
        public function __construct(public array &$depths) {}

        public function send(EmailAddress $to, IssuedPasswordReset $reset): PasswordResetDelivery
        {
            $this->depths[] = DB::transactionLevel();

            return PasswordResetDelivery::Sent;
        }
    });
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('holder@example.org');

    app(IssueAdministrativePasswordReset::class)($target->id, Access::actorFor($admin));

    expect($depths)->toBe([$baseline]);
});

it('rolls the token back, and sends nothing, if the audit event cannot be written', function () {
    $admin = Access::admin('admin@example.org');
    $target = Identity::savedActiveAccount('holder@example.org');
    Faults::auditFailsAt(1);

    expect(fn () => app(IssueAdministrativePasswordReset::class)($target->id, Access::actorFor($admin)))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    Mail::assertNothingSent();
});

// --- The recipient's inbox: a per-Account limit on operator-triggered reset mail ---------------------------------------
//
// The token store's one-a-minute rule bounds LIVE tokens, not mail: on its own it would still let an operator send one
// inbox about sixty messages an hour. The limit below is the same shape as invitation reissue (Phase 9): authentication,
// the capability and recent proof are all about the CALLER, and this one is about the RECIPIENT. Time moves only through
// Console::advance, never by sleeping, and each send is spaced past the one-minute rule so only THIS limit is measured.

/**
 * Sends one reset email, a minute after the last, so the one-a-minute token rule never gets in the way.
 *
 * @return TestResponse<Response>
 */
function sendResetAfterAMinute(Console $console, string $accountId): TestResponse
{
    Console::advance(61);

    return $console->post(resetPath($accountId));
}

it('bounds how much reset mail one Account can be sent, without getting in an operator\'s way, and keys it on the target', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org');
    $other = Identity::savedActiveAccount('another@example.org');
    $allowed = config()->integer('identity.credential_throttle.password_reset_by_operator.per_identifier');
    expect($allowed)->toBeGreaterThanOrEqual(5, 'a legitimate operator must be able to resend more than once or twice');

    for ($sent = 0; $sent < $allowed; $sent++) {
        sendResetAfterAMinute($console, $target->id->value)->assertOk();
    }

    $refused = sendResetAfterAMinute($console, $target->id->value);
    expect($refused->status())->toBe(429)
        ->and($refused->headers->get('Retry-After'))->not->toBeNull()
        ->and($refused->json())->toBe(['message' => 'Too many attempts. Try again later.']) // no Account or email detail
        ->and(Identity::events('password.reset_requested_by_operator'))->toHaveCount($allowed);
    Mail::assertSent(PasswordResetMail::class, $allowed); // the refused one sent, issued and recorded nothing

    // Keyed on the TARGET: another Account's allowance is its own.
    sendResetAfterAMinute($console, $other->id->value)->assertOk();
    Mail::assertSent(PasswordResetMail::class, $allowed + 1);
});

it('does not spend the target\'s allowance on a caller who may not do this at all', function () {
    // Authorization comes first, so a refused caller cannot exhaust the limit of the person being recovered, which would
    // turn a capability check into a denial of service against them. Asserted through the use case: what is pinned is the
    // ORDER of two steps inside it.
    $target = Identity::savedActiveAccount('holder@example.org');
    $guardian = Access::actorFor(Mfa::guardian('guardian@example.org'));
    $admin = Access::actorFor(Access::admin('second.admin@example.org'));
    $client = new ClientContext('127.0.0.1', 'test');
    $allowed = config()->integer('identity.credential_throttle.password_reset_by_operator.per_identifier');

    for ($attempt = 0; $attempt < $allowed + 3; $attempt++) {
        expect(fn () => app(SendManagedPasswordReset::class)($guardian, $target->id, $client))->toThrow(AccessDenied::class);
    }

    // The allowance is untouched: an authorized operator can still send the full number.
    for ($sent = 0; $sent < $allowed; $sent++) {
        Console::advance(61);
        app(SendManagedPasswordReset::class)($admin, $target->id, $client);
    }
    expect(Identity::events('password.reset_requested_by_operator'))->toHaveCount($allowed);
    Mail::assertSent(PasswordResetMail::class, $allowed);
});

it('does not spend the allowance on a request the step-up refuses, and still refuses it', function () {
    // The route refuses a stale proof before the use case runs, so the target's quota is not touched, and nothing is
    // issued or sent while the proof is stale. Once the operator re-proves, the full allowance is still there.
    [$console, , $factor] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org');
    $allowed = config()->integer('identity.credential_throttle.password_reset_by_operator.per_identifier');
    Console::advance(16 * 60);

    for ($attempt = 0; $attempt < $allowed + 3; $attempt++) {
        $console->post(resetPath($target->id->value))->assertForbidden()->assertJson(['verification_required' => true]);
    }
    Mail::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0)->and(Identity::events('password.reset_requested_by_operator'))->toBe([]);

    $console->post('/api/v1/security/verify', ['current_password' => Identity::PASSWORD, 'code' => Totp::next($factor['secret'])])->assertNoContent();
    for ($sent = 0; $sent < $allowed; $sent++) {
        sendResetAfterAMinute($console, $target->id->value)->assertOk();
    }
    expect(sendResetAfterAMinute($console, $target->id->value)->status())->toBe(429);
});

it('leaves the Account holder\'s own "I forgot my password" alone: the operator limit is its own counter, and the public answer is the same', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org');
    $allowed = config()->integer('identity.credential_throttle.password_reset_by_operator.per_identifier');
    for ($sent = 0; $sent < $allowed; $sent++) {
        sendResetAfterAMinute($console, $target->id->value)->assertOk();
    }
    expect(sendResetAfterAMinute($console, $target->id->value)->status())->toBe(429);

    // The operator limit does not lock the holder out of self-service, and what the public endpoint answers is exactly
    // what it answers for an address with no Account at all.
    Console::advance(61);
    $known = Recovery::forgot('holder@example.org');
    $unknown = Recovery::forgot('nobody@example.org');

    expect($known->status())->toBe(202)->and($unknown->status())->toBe(202)->and($known->json())->toBe($unknown->json());
    Mail::assertSent(PasswordResetMail::class, $allowed + 1);
});

it('keeps the one-minute token rule and the operator mail limit independent: each refuses on its own, with its own answer', function () {
    [$console] = Mfa::signedInAdmin();
    $target = Identity::savedActiveAccount('holder@example.org');

    // A generous mail limit, and the token rule still refuses an immediate second send (409, not 429).
    config(['identity.credential_throttle.password_reset_by_operator.per_identifier' => 100]);
    $console->post(resetPath($target->id->value))->assertOk();
    $console->post(resetPath($target->id->value))->assertStatus(409)->assertJson(['code' => 'password_reset_recently_requested']);

    // A one-message mail limit, and it refuses (429) even though the token rule would now allow another send.
    config(['identity.credential_throttle.password_reset_by_operator.per_identifier' => 1]);
    $second = Identity::savedActiveAccount('second@example.org');
    sendResetAfterAMinute($console, $second->id->value)->assertOk();
    expect(sendResetAfterAMinute($console, $second->id->value)->status())->toBe(429);
    Mail::assertSent(PasswordResetMail::class, 2);
});
