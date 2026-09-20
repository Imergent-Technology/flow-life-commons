<?php

declare(strict_types=1);

use App\Modules\Access\Application\BootstrapAdministrator;
use App\Modules\Identity\Application\DeliverInvitation;
use App\Modules\Identity\Application\InvitationDelivery;
use App\Modules\Identity\Application\InvitationDetails;
use App\Modules\Identity\Application\InvitationNotifier;
use App\Modules\Identity\Application\InviteAccount;
use App\Modules\Identity\Application\IssuedInvitation;
use App\Modules\Identity\Domain\InvitationChannel;
use App\Modules\Identity\Infrastructure\Mail\InvitationMail;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Identity;
use Tests\Support\Invitations;

/*
 * Delivered invitations (ADR 0024): the invitation CHANNEL decides whether accepting one shows the holder
 * controls the mailbox, and delivery is after the commit and outside it.
 */

it('leaves the email UNVERIFIED when an operator-delivered (bootstrap) invitation is accepted', function () {
    $issued = app(BootstrapAdministrator::class)(InvitationDetails::from('root@example.org', 'Root'))->invitation;

    Invitations::accept($issued->revealToken())->assertNoContent();

    $row = DB::table('accounts')->where('id', $issued->accountId->value)->first();
    expect($row?->status)->toBe('active')
        ->and($row?->email_verified_at)->toBeNull()
        ->and(DB::table('account_invitations')->value('channel'))->toBe('operator');
});

it('VERIFIES the email when an invitation the platform emailed is accepted', function () {
    $issued = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);

    Invitations::accept($issued->revealToken())->assertNoContent();

    $row = DB::table('accounts')->where('id', $issued->accountId->value)->first();
    expect($row?->status)->toBe('active')
        ->and($row?->email_verified_at)->not->toBeNull()
        ->and(DB::table('account_invitations')->value('channel'))->toBe('email')
        ->and(Identity::context(Identity::events('invitation.accepted')[0]))->toBe(['issued_by' => 'platform', 'channel' => 'email']);
});

it('records the channel on the invitation event, and the platform-issued one is still not vouched for by an Account', function () {
    app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);

    expect(Identity::context(Identity::events('account.invited')[0]))->toBe(['expires_in_days' => 7, 'channel' => 'email']);
});

it('mails the link with the token in the URL FRAGMENT, and nowhere else', function () {
    Mail::fake();
    $issued = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);

    $outcome = app(DeliverInvitation::class)($issued, null, 'issued');

    expect($outcome)->toBe(InvitationDelivery::Sent);
    Mail::assertSent(InvitationMail::class, 1);
    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail) use ($issued): bool {
        $html = $mail->render();

        return $mail->hasTo('new@example.org')
            && str_contains($html, '/accept-invitation#token='.$issued->revealToken())
            && ! str_contains($html, '?token=')
            && ! str_contains($html, $issued->accountId->value)
            && ! str_contains($html, $issued->personId->value);
    });
});

it('reports a delivery failure, records it, and keeps the committed Account and invitation', function () {
    Log::spy();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));
    $issued = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);

    $outcome = app(DeliverInvitation::class)($issued, null, 'issued');

    $events = Identity::events('invitation.delivery_failed');
    expect($outcome)->toBe(InvitationDelivery::Failed)
        ->and(DB::table('accounts')->where('id', $issued->accountId->value)->value('status'))->toBe('invited')
        ->and(DB::table('account_invitations')->count())->toBe(1)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->outcome)->toBe('failure')
        ->and($events[0]->subject_account_id)->toBe($issued->accountId->value)
        ->and(Identity::context($events[0]))->toBe(['action' => 'issued'])
        // Neither the token nor the link is in the audit trail.
        ->and(json_encode(DB::table('security_events')->get()->all(), JSON_THROW_ON_ERROR))->not->toContain($issued->revealToken());
});

it('never writes the raw token to the log when delivery fails', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));
    $lines = [];
    Log::listen(function (MessageLogged $message) use (&$lines): void {
        $lines[] = $message->message.json_encode($message->context);
    });
    $issued = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);

    app(InvitationNotifier::class)->send($issued);

    expect(implode("\n", $lines))->not->toContain($issued->revealToken());
});

it('does not persist the raw token anywhere: only its hash', function () {
    $issued = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);
    $token = $issued->revealToken();

    $everything = json_encode([
        DB::table('account_invitations')->get()->all(), DB::table('security_events')->get()->all(),
        DB::table('accounts')->get()->all(), DB::table('jobs')->get()->all(),
    ], JSON_THROW_ON_ERROR);

    expect($everything)->not->toContain($token)
        ->and(DB::table('account_invitations')->value('token_hash'))->toBe(hash('sha256', $token))
        ->and(print_r($issued, true))->not->toContain($token);
});

it('does not mail before the invitation has committed: the message is not sent from inside the issuing transaction', function () {
    // The use case that issues never sends. Whatever delivers does so afterwards, so a rolled-back invitation
    // can never have been mailed. Here the notifier is asserted untouched while InviteAccount runs.
    $sent = new ArrayObject;
    app()->instance(InvitationNotifier::class, new class($sent) implements InvitationNotifier
    {
        /** @param  ArrayObject<int, string>  $sent */
        public function __construct(private ArrayObject $sent) {}

        public function send(IssuedInvitation $invitation): InvitationDelivery
        {
            $this->sent[] = $invitation->email;

            return InvitationDelivery::Sent;
        }
    });

    DB::transaction(function () use ($sent): void {
        app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);
        expect(count($sent))->toBe(0);
    });

    expect(count($sent))->toBe(0);
});

it('accepts an emailed invitation exactly once', function () {
    $issued = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);

    Invitations::accept($issued->revealToken())->assertNoContent();
    Invitations::accept($issued->revealToken())->assertStatus(422);

    expect(DB::table('accounts')->whereNotNull('email_verified_at')->count())->toBe(1);
});
