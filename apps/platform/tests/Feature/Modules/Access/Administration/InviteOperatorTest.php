<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\ReissueOperatorInvitation;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\InvitationDelivery;
use App\Modules\Identity\Infrastructure\Mail\InvitationMail;
use App\Shared\Domain\AccountId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\FakeInvitationNotifier;
use Tests\Support\Faults;
use Tests\Support\Identity;
use Tests\Support\Invitations;
use Tests\Support\Mfa;

/*
 * Inviting a new operator (ADR 0024): state in one transaction, the message after the commit and outside it, the
 * secret only in the mail.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-22 12:00:00');
    Mail::fake();
});

/** The token in the emailed link. */
function mailedToken(): string
{
    $sent = Mail::sent(InvitationMail::class);
    expect($sent)->toHaveCount(1);
    $mail = $sent->first();
    assert($mail instanceof InvitationMail);
    preg_match('~/accept-invitation#token=([A-Za-z0-9_-]{43})~', $mail->render(), $m);

    return $m[1] ?? '';
}

it('creates a Person, an invited Account and an EMAIL invitation, and mails the link', function () {
    [$console, $admin] = Mfa::signedInAdmin();

    $response = $console->post('/api/v1/admin/invitations', ['email' => 'New.Person@Example.org', 'display_name' => 'New Person'])->assertCreated();

    expect($response->json('delivery'))->toBe(['status' => 'sent'])
        ->and($response->json('account.status'))->toBe('invited')
        ->and($response->json('account.email'))->toBe('New.Person@Example.org')
        ->and($response->json('account.invitation.delivery'))->toBe('email')
        ->and($response->json('account.assignments'))->toBe([])
        ->and(DB::table('accounts')->where('email_canonical', 'new.person@example.org')->value('password_hash'))->toBeNull()
        ->and(DB::table('account_invitations')->value('channel'))->toBe('email')
        ->and(DB::table('account_invitations')->value('invited_by_account_id'))->toBe($admin->id->value)
        ->and(Identity::events('account.invited'))->toHaveCount(1);
    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('New.Person@Example.org'));
});

it('NEVER puts the invitation secret in the response, the audit trail, the log or the database', function () {
    Log::spy();
    [$console] = Mfa::signedInAdmin();

    $response = $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New Person'])->assertCreated();
    $token = mailedToken();

    expect($token)->not->toBe('')
        ->and($response->getContent())->not->toContain($token)
        ->and(strtolower((string) $response->getContent()))->not->toContain('token')
        ->and(Mfa::auditText())->not->toContain($token)
        ->and(json_encode(DB::table('account_invitations')->get()->all()))->not->toContain($token)
        ->and(DB::table('account_invitations')->value('token_hash'))->toBe(hash('sha256', $token));
});

it('lets the invitee accept, be verified, and be taken to enrolment because they were given Console access', function () {
    [$console] = Mfa::signedInAdmin();
    $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New Person', 'initial_assignments' => ['guardian']])->assertCreated();

    Invitations::accept(mailedToken(), 'a long enough passphrase for the invitee')->assertNoContent();

    $row = DB::table('accounts')->where('email_canonical', 'new@example.org')->first();
    expect($row?->status)->toBe('active')->and($row?->email_verified_at)->not->toBeNull();
    (new Console)->login('new@example.org', 'a long enough passphrase for the invitee')->assertStatus(202)->assertJson(['next' => 'enrollment']);
});

it('records initial role assignments through the real use case, audited, all in the same transaction', function () {
    [$console, $admin] = Mfa::signedInAdmin();

    $response = $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New Person', 'initial_assignments' => ['guardian', 'guardian']])->assertCreated();

    $events = Identity::events('role.granted');
    expect($response->json('account.assignments.0.key'))->toBe('guardian')
        ->and($response->json('account.assignments'))->toHaveCount(1) // a repeated key is the same grant
        ->and($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($admin->id->value);
});

it('leaves NO partial state and sends NO mail when a role assignment fails', function () {
    [$console] = Mfa::signedInAdmin();
    $before = [DB::table('people')->count(), DB::table('accounts')->count(), DB::table('account_invitations')->count(), DB::table('security_events')->count()];
    Faults::roleAssignmentFails();

    $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New Person', 'initial_assignments' => ['guardian']])->assertStatus(500);

    expect([DB::table('people')->count(), DB::table('accounts')->count(), DB::table('account_invitations')->count(), DB::table('security_events')->count()])->toBe($before);
    Mail::assertNothingSent();
});

it('refuses an unknown role key before anything is created', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New', 'initial_assignments' => ['guardian', 'console.access']])
        ->assertStatus(422)->assertJson(['code' => 'unknown_role']);

    expect(DB::table('accounts')->count())->toBe(1);
    Mail::assertNothingSent();
});

it('invites with NO role at all: an invited Account without Console access simply cannot enter it', function () {
    [$console] = Mfa::signedInAdmin();
    $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New'])->assertCreated();
    Invitations::accept(mailedToken(), 'a long enough passphrase for the invitee')->assertNoContent();

    $invitee = new Console;
    $invitee->login('new@example.org', 'a long enough passphrase for the invitee')->assertOk();
    expect($invitee->me()->json('capabilities'))->toBe([]);
});

it('fails safely for an address already in use, whatever its case, and changes and sends nothing', function () {
    [$console] = Mfa::signedInAdmin();
    Identity::savedActiveAccount('taken@example.org');
    $before = [DB::table('people')->count(), DB::table('security_events')->count()];

    $console->post('/api/v1/admin/invitations', ['email' => 'TAKEN@Example.org', 'display_name' => 'Someone Else'])
        ->assertStatus(409)->assertJson(['code' => 'email_already_in_use']);

    expect([DB::table('people')->count(), DB::table('security_events')->count()])->toBe($before);
    Mail::assertNothingSent();
});

it('validates the address and the name', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/invitations', ['email' => 'not an address', 'display_name' => 'New'])->assertStatus(422)->assertJsonPath('errors.email.0', fn ($m) => is_string($m));
    $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => str_repeat('x', 300)])->assertStatus(422);
    $console->post('/api/v1/admin/invitations', [])->assertStatus(422);
    expect(DB::table('accounts')->count())->toBe(1);
});

it('sends the message only AFTER the transaction has committed, and never from inside it', function () {
    $notifier = FakeInvitationNotifier::install();
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New', 'initial_assignments' => ['guardian']])->assertCreated();

    // Sent once, and by then every transaction the request opened (Person, Account, invitation, role, audit) was closed.
    expect($notifier->sent)->toHaveCount(1)
        ->and($notifier->sent[0]['level'])->toBe($notifier->baseline)
        ->and($notifier->sent[0]['email'])->toBe('new@example.org');
});

it('REPORTS a delivery failure, keeps the committed Account, and records it', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));
    [$console, $admin] = Mfa::signedInAdmin();

    $response = $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New'])->assertCreated();

    $failed = Identity::events('invitation.delivery_failed');
    expect($response->json('delivery'))->toBe(['status' => InvitationDelivery::Failed->value])
        ->and($response->json('account.status'))->toBe('invited')
        ->and(DB::table('accounts')->where('email_canonical', 'new@example.org')->exists())->toBeTrue()
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->actor_account_id)->toBe($admin->id->value);
});

it('reissues: the OLD invitation dies, a fresh one is mailed, and it works', function () {
    [$console] = Mfa::signedInAdmin();
    $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New'])->assertCreated();
    $old = mailedToken();
    $id = Api::string(DB::table('accounts')->where('email_canonical', 'new@example.org')->value('id'));
    Mail::fake();

    $response = $console->post("/api/v1/admin/accounts/{$id}/invitation")->assertOk();
    $fresh = mailedToken();

    expect($response->json('delivery'))->toBe(['status' => 'sent'])
        ->and($fresh)->not->toBe($old)
        ->and(DB::table('account_invitations')->count())->toBe(1)
        ->and(Identity::events('invitation.reissued'))->toHaveCount(1)
        ->and($response->getContent())->not->toContain($fresh);
    Invitations::accept($old)->assertStatus(422);
    Invitations::accept($fresh, 'a long enough passphrase for the invitee')->assertNoContent();
});

it('reissues after a FAILED delivery, which is the remedy the failure calls for', function () {
    $notifier = FakeInvitationNotifier::install();
    $notifier->outcomes = [InvitationDelivery::Failed]; // the first message does not go; the next does
    [$console] = Mfa::signedInAdmin();
    $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New'])->assertCreated()->assertJsonPath('delivery.status', 'failed');
    $id = Api::string(DB::table('accounts')->where('email_canonical', 'new@example.org')->value('id'));

    $console->post("/api/v1/admin/accounts/{$id}/invitation")->assertOk()->assertJsonPath('delivery.status', 'sent');

    expect($notifier->sent)->toHaveCount(2)
        ->and($notifier->sent[1]['token'])->not->toBe($notifier->sent[0]['token'])
        ->and(DB::table('account_invitations')->count())->toBe(1)
        ->and(DB::table('account_invitations')->value('token_hash'))->toBe(hash('sha256', $notifier->sent[1]['token']));
    Invitations::accept($notifier->sent[0]['token'])->assertStatus(422); // the unsent one never worked
    Invitations::accept($notifier->sent[1]['token'], 'a long enough passphrase for the invitee')->assertNoContent();
});

it('refuses to reissue for an ACTIVE Account, and for an unknown one', function () {
    [$console] = Mfa::signedInAdmin();
    $active = Identity::savedActiveAccount('active@example.org');

    $console->post("/api/v1/admin/accounts/{$active->id->value}/invitation")->assertStatus(409)->assertJson(['code' => 'invitation_not_issuable']);
    $console->post('/api/v1/admin/accounts/01jzzzzzzzzzzzzzzzzzzzzzzz/invitation')->assertNotFound();
    Mail::assertNothingSent();
    expect(Identity::events('invitation.reissued'))->toBe([]);
});

it('bounds how much invitation mail one address can be sent, without getting in an operator\'s way', function () {
    // Phase 8 left reissue unthrottled, reasoning that the caller is authenticated, capability-checked and
    // recently verified — all true, and all about the CALLER. The limit added in Phase 9 is about the
    // RECIPIENT: a reissue mails somebody else's inbox, and nothing else bounded how often.
    //
    // So it has to do two things, and both are asserted here: never refuse an operator doing the ordinary
    // thing, and stop before an inbox is flooded.
    [$console] = Mfa::signedInAdmin();
    $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New'])->assertCreated();
    $id = Api::string(DB::table('accounts')->where('email_canonical', 'new@example.org')->value('id'));

    $allowed = config()->integer('identity.credential_throttle.invitation_reissue.per_identifier');
    expect($allowed)->toBeGreaterThanOrEqual(5, 'a legitimate operator must be able to resend more than once or twice');

    for ($sent = 0; $sent < $allowed; $sent++) {
        $console->post("/api/v1/admin/accounts/{$id}/invitation")->assertOk();
    }

    $refused = $console->post("/api/v1/admin/accounts/{$id}/invitation");
    expect($refused->status())->toBe(429)
        ->and($refused->headers->get('Retry-After'))->not->toBeNull();

    // It is keyed on the TARGET, so one person's invitation cannot hold up another's.
    $console->post('/api/v1/admin/invitations', ['email' => 'other@example.org', 'display_name' => 'Other'])->assertCreated();
    $other = Api::string(DB::table('accounts')->where('email_canonical', 'other@example.org')->value('id'));
    $console->post("/api/v1/admin/accounts/{$other}/invitation")->assertOk();
});

it('does not spend the target\'s allowance on a caller who may not reissue at all', function () {
    // Authorization comes first, so a refused caller cannot exhaust somebody else's limit — which would
    // turn a capability check into a denial-of-service against the person being invited. Asserted through
    // the use case rather than two browsers: what is being pinned is the ORDER of two steps inside it.
    [$console] = Mfa::signedInAdmin();
    $console->post('/api/v1/admin/invitations', ['email' => 'new@example.org', 'display_name' => 'New'])->assertCreated();
    $id = AccountId::fromString(Api::string(DB::table('accounts')->where('email_canonical', 'new@example.org')->value('id')));

    $guardian = Access::actorFor(Mfa::guardian('guardian@example.org'));
    $client = new ClientContext('127.0.0.1', 'test');
    $allowed = config()->integer('identity.credential_throttle.invitation_reissue.per_identifier');

    for ($attempt = 0; $attempt < $allowed + 3; $attempt++) {
        expect(fn () => app(ReissueOperatorInvitation::class)($guardian, $id, $client))->toThrow(AccessDenied::class);
    }

    // The target's allowance is untouched: an authorized operator can still reissue the full number.
    $admin = Access::actorFor(Access::admin('second.admin@example.org'));
    for ($sent = 0; $sent < $allowed; $sent++) {
        app(ReissueOperatorInvitation::class)($admin, $id, $client);
    }
    expect(Identity::events('invitation.reissued'))->toHaveCount($allowed);
});
