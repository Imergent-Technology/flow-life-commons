<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\InvitationToken;
use App\Shared\Domain\AccountId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

it('persists an invitation and reloads it faithfully', function () {
    $account = Identity::savedInvitedAccount();
    $inviter = AccountId::generate();
    $invitation = Identity::invitation($account, null, $inviter);
    app(AccountInvitationRepository::class)->save($invitation);

    $found = app(AccountInvitationRepository::class)->find($invitation->id);

    expect($found)->toEqual($invitation)
        ->and($found?->expiresAt)->toEqual(Identity::now()->modify('+7 days'))
        ->and($found?->acceptedAt)->toBeNull();
});

it('never stores the raw token anywhere, only its hash', function () {
    $token = InvitationToken::generate();
    $account = Identity::savedInvitedAccount();
    Identity::savedInvitation($account, $token);

    $everything = json_encode([
        DB::table('account_invitations')->get()->all(),
        DB::table('accounts')->get()->all(),
        DB::table('people')->get()->all(),
    ], JSON_THROW_ON_ERROR);

    expect($everything)->not->toContain($token->reveal())
        ->and(DB::table('account_invitations')->value('token_hash'))->toBe($token->hash());
});

it('finds an invitation by the presented token', function () {
    $token = InvitationToken::generate();
    $invitation = Identity::savedInvitation(Identity::savedInvitedAccount(), $token);

    $repository = app(AccountInvitationRepository::class);

    expect($repository->findByToken(InvitationToken::fromPresented($token->reveal())))->toEqual($invitation)
        ->and($repository->findByToken(InvitationToken::generate()))->toBeNull();
});

it('treats a token as case-sensitive, so a case variant finds nothing on either engine', function () {
    $token = InvitationToken::generate();
    Identity::savedInvitation(Identity::savedInvitedAccount(), $token);

    // Base64url is case-sensitive; the lookup goes through the lowercase-hex hash of the
    // exact secret, so altering case yields a different hash rather than relying on the
    // database's collation.
    $variant = InvitationToken::fromPresented(strtoupper($token->reveal()));

    expect($variant->reveal())->not->toBe($token->reveal())
        ->and(app(AccountInvitationRepository::class)->findByToken($variant))->toBeNull();
});

it('enforces a unique token hash', function () {
    $token = InvitationToken::generate();
    Identity::savedInvitation(Identity::savedInvitedAccount('one@example.org'), $token);
    $second = Identity::invitation(Identity::savedInvitedAccount('two@example.org'), $token);

    $error = Identity::violation(fn () => app(AccountInvitationRepository::class)->save($second));

    expect($error)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and(DB::table('account_invitations')->count())->toBe(1);
});

it('lets an Account be invited again, keeping earlier invitations', function () {
    $account = Identity::savedInvitedAccount();

    Identity::savedInvitation($account);
    Identity::savedInvitation($account);

    expect(DB::table('account_invitations')->where('account_id', $account->id->value)->count())->toBe(2);
});

it('persists one-time use and reports an accepted invitation as no longer usable', function () {
    $repository = app(AccountInvitationRepository::class);
    $invitation = Identity::savedInvitation(Identity::savedInvitedAccount());
    $acceptedAt = Identity::now()->modify('+1 hour');

    $repository->save($invitation->accept($acceptedAt));
    $found = $repository->find($invitation->id);

    expect($found?->isAccepted())->toBeTrue()
        ->and($found?->acceptedAt)->toEqual($acceptedAt)
        ->and($found?->isUsableAt($acceptedAt->modify('+1 minute')))->toBeFalse()
        ->and(DB::table('account_invitations')->count())->toBe(1);
});

it('persists expiry as an instant that survives the round trip exactly', function () {
    $invitation = Identity::savedInvitation(Identity::savedInvitedAccount());
    $found = app(AccountInvitationRepository::class)->find($invitation->id);

    expect($found?->isUsableAt($invitation->expiresAt->modify('-1 second')))->toBeTrue()
        ->and($found?->isExpiredAt($invitation->expiresAt))->toBeTrue()
        ->and(DB::table('account_invitations')->value('expires_at'))->toBe('2026-09-26 12:00:00');
});

it('stores the token hash as lowercase hex so no collation can make two hashes equal', function () {
    Identity::savedInvitation(Identity::savedInvitedAccount());

    expect(DB::table('account_invitations')->value('token_hash'))->toMatch('/^[0-9a-f]{64}$/');
});
