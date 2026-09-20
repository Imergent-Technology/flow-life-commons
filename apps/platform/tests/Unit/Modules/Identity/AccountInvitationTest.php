<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\AccountInvitation;
use App\Modules\Identity\Domain\AccountInvitationId;
use App\Modules\Identity\Domain\InvitationNotUsable;
use App\Modules\Identity\Domain\InvitationToken;
use App\Shared\Domain\AccountId;
use Tests\Support\Identity;

function invitationFor(?InvitationToken $token = null): AccountInvitation
{
    return Identity::invitation(Identity::invitedAccount(Identity::person()), $token);
}

it('generates 32 bytes of URL-safe randomness', function () {
    $token = InvitationToken::generate();

    expect($token->reveal())->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(InvitationToken::generate()->reveal())->not->toBe($token->reveal())
        ->and(strlen(base64_decode(strtr($token->reveal(), '-_', '+/').'=', true) ?: ''))->toBe(32);
});

it('hashes to lowercase hex SHA-256, deterministically, and never equals the secret', function () {
    $token = InvitationToken::generate();

    expect($token->hash())->toMatch('/^[0-9a-f]{64}$/')
        ->and($token->hash())->toBe(hash('sha256', $token->reveal()))
        ->and(InvitationToken::fromPresented($token->reveal())->hash())->toBe($token->hash())
        ->and($token->hash())->not->toContain($token->reveal());
});

it('does not reveal its secret when dumped', function () {
    $token = InvitationToken::generate();

    expect(print_r($token, true))->not->toContain($token->reveal())
        ->and(var_export($token->__debugInfo(), true))->not->toContain($token->reveal());
});

it('rejects a presented token that could not have been issued', function (string $presented) {
    InvitationToken::fromPresented($presented);
})->with(['', 'short', str_repeat('a', 42), str_repeat('a', 44), str_repeat('a', 42).'=', str_repeat('a', 42).' '])
    ->throws(InvalidArgumentException::class);

it('holds only the hash of the token it was issued with', function () {
    $token = InvitationToken::generate();
    $invitation = invitationFor($token);

    expect($invitation->tokenHash)->toBe($token->hash())
        ->and(serialize($invitation))->not->toContain($token->reveal());
});

it('is usable strictly before it expires and expired at that instant and after', function () {
    $invitation = invitationFor();
    $expiry = $invitation->expiresAt;

    expect($invitation->isUsableAt($expiry->modify('-1 second')))->toBeTrue()
        ->and($invitation->isExpiredAt($expiry->modify('-1 second')))->toBeFalse()
        ->and($invitation->isExpiredAt($expiry))->toBeTrue()
        ->and($invitation->isUsableAt($expiry))->toBeFalse()
        ->and($invitation->isUsableAt($expiry->modify('+1 day')))->toBeFalse();
});

it('is single-use: acceptance is recorded once and cannot repeat', function () {
    $invitation = invitationFor();
    $accepted = $invitation->accept(Identity::now()->modify('+1 hour'));

    expect($accepted->isAccepted())->toBeTrue()
        ->and($accepted->acceptedAt)->toEqual(Identity::now()->modify('+1 hour'))
        ->and($accepted->isUsableAt(Identity::now()->modify('+2 hours')))->toBeFalse()
        ->and($invitation->isAccepted())->toBeFalse()
        ->and(fn () => $accepted->accept(Identity::now()->modify('+2 hours')))->toThrow(InvitationNotUsable::class);
});

it('cannot be accepted after it expires', function () {
    $invitation = invitationFor();

    $invitation->accept($invitation->expiresAt);
})->throws(InvitationNotUsable::class);

it('cannot be issued already expired', function () {
    AccountInvitation::issue(
        AccountInvitationId::generate(), AccountId::generate(), InvitationToken::generate(),
        Identity::now(), Identity::now(),
    );
})->throws(InvitationNotUsable::class);

it('records who invited, or nobody when the platform issues it', function () {
    $account = Identity::invitedAccount(Identity::person());
    $inviter = AccountId::generate();

    expect(Identity::invitation($account, null, $inviter)->invitedByAccountId)->toEqual($inviter)
        ->and(Identity::invitation($account)->invitedByAccountId)->toBeNull();
});
