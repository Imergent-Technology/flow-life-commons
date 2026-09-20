<?php

declare(strict_types=1);

use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\InvitationDetails;
use App\Modules\Identity\Application\InvitationNotIssuable;
use App\Modules\Identity\Application\InviteAccount;
use App\Modules\Identity\Application\ReissueInvitation;
use App\Modules\Identity\Domain\InvitationChannel;
use App\Shared\Domain\AccountId;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Faults;
use Tests\Support\Identity;
use Tests\Support\Invitations;

it('issues a fresh invitation, and the old token stops working', function () {
    $first = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);

    $second = app(ReissueInvitation::class)($first->accountId);

    expect($second->accountId->equals($first->accountId))->toBeTrue()
        ->and($second->revealToken())->not->toBe($first->revealToken())
        ->and(DB::table('account_invitations')->count())->toBe(1) // never two usable at once
        ->and(DB::table('account_invitations')->value('token_hash'))->toBe(hash('sha256', $second->revealToken()));

    Invitations::accept($first->revealToken())->assertStatus(422);
    expect(DB::table('accounts')->value('status'))->toBe('invited');

    Invitations::accept($second->revealToken())->assertNoContent();
    expect(DB::table('accounts')->value('status'))->toBe('active');
});

it('preserves the Account and the Person, and records who reissued it', function () {
    $admin = Access::admin('admin@example.org');
    $first = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);

    app(ReissueInvitation::class)($first->accountId, Access::actorFor($admin));

    $events = Identity::events('invitation.reissued');
    expect(DB::table('accounts')->where('email_canonical', 'new@example.org')->count())->toBe(1)
        ->and(DB::table('people')->where('display_name', 'New Person')->count())->toBe(1)
        ->and($events)->toHaveCount(1)
        ->and($events[0]->actor_account_id)->toBe($admin->id->value)
        ->and($events[0]->subject_account_id)->toBe($first->accountId->value)
        ->and(Identity::context($events[0]))->toBe(['expires_in_days' => 7, 'channel' => 'email', 'replaced' => 1])
        ->and(DB::table('account_invitations')->value('invited_by_account_id'))->toBe($admin->id->value);
});

it('replaces an EXPIRED invitation with a usable one', function () {
    $first = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);
    Carbon::setTestNow(now()->addDays(8));

    Invitations::accept($first->revealToken())->assertStatus(422);
    $second = app(ReissueInvitation::class)($first->accountId);

    Invitations::accept($second->revealToken())->assertNoContent();
});

it('makes the reissued invitation an EMAIL one, so accepting it verifies the mailbox, even after a bootstrap-style first one', function () {
    $first = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person')); // operator-delivered
    $second = app(ReissueInvitation::class)($first->accountId);

    Invitations::accept($second->revealToken())->assertNoContent();

    expect(DB::table('accounts')->value('email_verified_at'))->not->toBeNull();
});

it('refuses to reissue for an ACTIVE Account, and changes nothing', function () {
    $account = Identity::savedActiveAccount();

    expect(fn () => app(ReissueInvitation::class)($account->id))->toThrow(InvitationNotIssuable::class)
        ->and(DB::table('account_invitations')->count())->toBe(0)
        ->and(Identity::events('invitation.reissued'))->toBe([]);
});

it('refuses to reissue for a DISABLED Account: enable it first', function () {
    $invited = Identity::savedInvitedAccount();
    DB::table('accounts')->where('id', $invited->id->value)->update(['status' => 'disabled', 'disabled_at' => '2026-09-19 12:00:00']);

    expect(fn () => app(ReissueInvitation::class)($invited->id))->toThrow(InvitationNotIssuable::class);
});

it('fails for an unknown Account', function () {
    expect(fn () => app(ReissueInvitation::class)(AccountId::generate()))->toThrow(AccountNotFound::class);
});

it('rolls the whole reissue back when its audit write fails: the old invitation is still there', function () {
    $first = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);
    Faults::auditFailsAt(1);

    expect(fn () => app(ReissueInvitation::class)($first->accountId))->toThrow(RuntimeException::class)
        ->and(DB::table('account_invitations')->value('token_hash'))->toBe(hash('sha256', $first->revealToken()));
});

it('takes the invitation locks BEFORE the Account lock: the order acceptance takes them in, so the two cannot deadlock', function () {
    // Acceptance locks the invitation, then the Account. A reissue that locked the Account first could wait on an
    // acceptance that is itself waiting for the Account, and the database would have to kill one of them. The
    // outcome of a race cannot show this (the Account lock alone serialises the outcomes), so the ORDER is pinned.
    $first = app(InviteAccount::class)(InvitationDetails::from('new@example.org', 'New Person'), channel: InvitationChannel::Email);
    $locking = [];
    DB::listen(function (QueryExecuted $query) use (&$locking): void {
        if (preg_match('/\bfrom\s+["`]?(account_invitations|accounts)["`]?.*\bfor update\b/is', $query->sql, $m) === 1) {
            $locking[] = $m[1];
        }
    });

    app(ReissueInvitation::class)($first->accountId);

    expect($locking)->toBe(['account_invitations', 'accounts']);
});
