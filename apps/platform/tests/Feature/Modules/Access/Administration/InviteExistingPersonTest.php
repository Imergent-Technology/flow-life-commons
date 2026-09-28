<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\InviteExistingPerson;
use App\Modules\Identity\Application\InvitationDelivery;
use App\Modules\Identity\Infrastructure\Mail\InvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Access;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\FakeInvitationNotifier;
use Tests\Support\Identity;
use Tests\Support\Invitations;
use Tests\Support\Mfa;

/*
 * POST /admin/people/{person}/invitation (ADR 0032, Work Package 1): inviting an existing Person — the
 * operator-facing sibling of POST /admin/invitations, sharing its authority, delivery and response shape,
 * but for a Person who already exists (typically registered with no Account by Membership) rather than a
 * brand new one. Membership-specific setup is deliberately NOT used here: Membership's own tests prove the
 * "register a Person with no Account" side; this proves the invitation side alone, against a Person however
 * one came to exist.
 */

beforeEach(function () {
    Mail::fake();
});

function mailedInvitationToken(): string
{
    $sent = Mail::sent(InvitationMail::class);
    expect($sent)->toHaveCount(1);
    $mail = $sent->first();
    assert($mail instanceof InvitationMail);
    preg_match('~/accept-invitation#token=([A-Za-z0-9_-]{43})~', $mail->render(), $m);

    return $m[1] ?? '';
}

it('invites an existing Person: creates no new Person, mails the link, and never returns the secret', function () {
    [$console, $admin] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Existing Person');
    $peopleBefore = DB::table('people')->count();

    $response = $console->post("/api/v1/admin/people/{$person->id->value}/invitation", ['email' => 'Existing.Person@Example.org'])->assertCreated();

    expect($response->json('delivery'))->toBe(['status' => 'sent'])
        ->and($response->json('account.person_id'))->toBe($person->id->value)
        ->and($response->json('account.status'))->toBe('invited')
        ->and($response->json('account.email'))->toBe('Existing.Person@Example.org')
        ->and($response->json('account.invitation.delivery'))->toBe('email')
        ->and($response->json('account.assignments'))->toBe([])
        ->and(DB::table('people')->count())->toBe($peopleBefore)
        ->and(DB::table('account_invitations')->value('channel'))->toBe('email')
        ->and(DB::table('account_invitations')->value('invited_by_account_id'))->toBe($admin->id->value)
        ->and(Identity::events('account.invited'))->toHaveCount(1);
    Mail::assertSent(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('Existing.Person@Example.org'));
    expect($response->getContent())->not->toContain(mailedInvitationToken());
});

it('lets the invited existing Person accept and sign in, with no Console access unless granted', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson('Existing Person');
    $console->post("/api/v1/admin/people/{$person->id->value}/invitation", ['email' => 'existing@example.org'])->assertCreated();

    Invitations::accept(mailedInvitationToken(), 'a long enough passphrase for the invitee')->assertNoContent();

    $row = DB::table('accounts')->where('person_id', $person->id->value)->first();
    expect($row?->status)->toBe('active')->and($row?->email_verified_at)->not->toBeNull();
    $invitee = new Console;
    $invitee->login('existing@example.org', 'a long enough passphrase for the invitee')->assertOk();
    expect($invitee->me()->json('capabilities'))->toBe([]);
});

it('needs identity.invitations.issue, the same capability POST /admin/invitations needs', function () {
    $person = Identity::savedPerson();
    // A Guardian holds console.access only, not identity.invitations.issue, and is refused.
    $guardian = Access::actorFor(Mfa::guardian('guardian@example.org'));

    expect(fn () => app(InviteExistingPerson::class)($guardian, $person->id, 'x@example.org'))
        ->toThrow(AccessDenied::class);
    Mail::assertNothingSent();
});

it('refuses a Person that does not exist', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/people/01jzzzzzzzzzzzzzzzzzzzzzzz/invitation', ['email' => 'x@example.org'])
        ->assertStatus(404)->assertJson(['code' => 'person_not_found']);
    Mail::assertNothingSent();
});

it('refuses a Person who already has an Account, and sends nothing', function () {
    [$console] = Mfa::signedInAdmin();
    $existing = Identity::savedActiveAccount('has-account@example.org');

    $console->post("/api/v1/admin/people/{$existing->personId->value}/invitation", ['email' => 'new-address@example.org'])
        ->assertStatus(409)->assertJson(['code' => 'person_already_has_account']);
    Mail::assertNothingSent();
});

it('refuses an address already in use, and sends nothing', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    Identity::savedActiveAccount('taken@example.org');

    $console->post("/api/v1/admin/people/{$person->id->value}/invitation", ['email' => 'TAKEN@Example.org'])
        ->assertStatus(409)->assertJson(['code' => 'email_already_in_use']);
    Mail::assertNothingSent();
    expect(DB::table('accounts')->where('person_id', $person->id->value)->exists())->toBeFalse();
});

it('validates the email address', function () {
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();

    $console->post("/api/v1/admin/people/{$person->id->value}/invitation", ['email' => 'not an address'])
        ->assertStatus(422)->assertJsonPath('errors.email.0', fn ($m) => is_string($m));
    $console->post("/api/v1/admin/people/{$person->id->value}/invitation", [])->assertStatus(422);
    expect(DB::table('accounts')->where('person_id', $person->id->value)->exists())->toBeFalse();
});

it('rejects a malformed Person id in the path with a 404, not a 500', function () {
    [$console] = Mfa::signedInAdmin();

    $console->post('/api/v1/admin/people/not-a-ulid/invitation', ['email' => 'x@example.org'])->assertNotFound();
});

it('REPORTS a delivery failure, keeps the committed Account, and records it', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));
    [$console, $admin] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();

    $response = $console->post("/api/v1/admin/people/{$person->id->value}/invitation", ['email' => 'existing@example.org'])->assertCreated();

    $failed = Identity::events('invitation.delivery_failed');
    expect($response->json('delivery'))->toBe(['status' => InvitationDelivery::Failed->value])
        ->and($response->json('account.status'))->toBe('invited')
        ->and(DB::table('accounts')->where('person_id', $person->id->value)->exists())->toBeTrue()
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->actor_account_id)->toBe($admin->id->value);
});

it('reissues after a failed delivery, through the SAME reissue endpoint every other invited Account uses', function () {
    $notifier = FakeInvitationNotifier::install();
    $notifier->outcomes = [InvitationDelivery::Failed]; // the first message does not go; the next does
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();
    $response = $console->post("/api/v1/admin/people/{$person->id->value}/invitation", ['email' => 'existing@example.org'])
        ->assertCreated()->assertJsonPath('delivery.status', 'failed');
    $id = Api::string($response->json('account.id'));

    $console->post("/api/v1/admin/accounts/{$id}/invitation")->assertOk()->assertJsonPath('delivery.status', 'sent');

    expect($notifier->sent)->toHaveCount(2)
        ->and($notifier->sent[1]['token'])->not->toBe($notifier->sent[0]['token'])
        ->and(DB::table('account_invitations')->count())->toBe(1);
    Invitations::accept($notifier->sent[0]['token'])->assertStatus(422); // the unsent one never worked
    Invitations::accept($notifier->sent[1]['token'], 'a long enough passphrase for the invitee')->assertNoContent();
});

it('never puts the invitation secret in the response, the audit trail, the log or the database', function () {
    Log::spy();
    [$console] = Mfa::signedInAdmin();
    $person = Identity::savedPerson();

    $response = $console->post("/api/v1/admin/people/{$person->id->value}/invitation", ['email' => 'existing@example.org'])->assertCreated();
    $token = mailedInvitationToken();

    expect($token)->not->toBe('')
        ->and($response->getContent())->not->toContain($token)
        ->and(strtolower((string) $response->getContent()))->not->toContain('token')
        ->and(Mfa::auditText())->not->toContain($token)
        ->and(json_encode(DB::table('account_invitations')->get()->all()))->not->toContain($token)
        ->and(DB::table('account_invitations')->value('token_hash'))->toBe(hash('sha256', $token));
});
