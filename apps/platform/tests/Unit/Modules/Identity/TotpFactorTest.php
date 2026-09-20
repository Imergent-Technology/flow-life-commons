<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\InvalidAccountState;
use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorId;
use App\Shared\Domain\AccountId;
use Tests\Support\Identity;

function pendingFactor(): TotpFactor
{
    return TotpFactor::begin(TotpFactorId::generate(), AccountId::generate(), 'cipher-one', Identity::now());
}

it('begins with a pending secret and nothing active: generating a secret enrols nothing', function () {
    $factor = pendingFactor();

    expect($factor->isActive())->toBeFalse()
        ->and($factor->secretCiphertext)->toBeNull()
        ->and($factor->enrolledAt)->toBeNull()
        ->and($factor->pendingCiphertext)->toBe('cipher-one');
});

it('becomes active only when the pending secret is confirmed, remembering the step that proved it', function () {
    $later = Identity::now()->modify('+1 minute');
    $active = pendingFactor()->confirmPending(59_000_001, $later);

    expect($active->isActive())->toBeTrue()
        ->and($active->secretCiphertext)->toBe('cipher-one')
        ->and($active->pendingCiphertext)->toBeNull()
        ->and($active->enrolledAt)->toEqual($later)
        ->and($active->lastUsedStep)->toBe(59_000_001);
});

it('cannot confirm what was never generated', function () {
    pendingFactor()->confirmPending(1, Identity::now())->confirmPending(2, Identity::now());
})->throws(InvalidAccountState::class);

it('keeps the active secret working while a replacement is pending, and drops it only on confirmation', function () {
    $active = pendingFactor()->confirmPending(10, Identity::now());
    $replacing = $active->withPending('cipher-two', Identity::now()->modify('+1 hour'));

    expect($replacing->secretCiphertext)->toBe('cipher-one')       // still the live one
        ->and($replacing->isActive())->toBeTrue()
        ->and($replacing->pendingCiphertext)->toBe('cipher-two');

    $switched = $replacing->confirmPending(20, Identity::now()->modify('+2 hours'));

    expect($switched->secretCiphertext)->toBe('cipher-two')
        ->and($switched->pendingCiphertext)->toBeNull()
        ->and($switched->enrolledAt)->toEqual($active->enrolledAt);   // first enrolment time is kept
});

it('lets a new pending secret replace an earlier one, so an abandoned attempt strands no one', function () {
    $second = pendingFactor()->withPending('cipher-two', Identity::now()->modify('+5 minutes'));

    expect($second->pendingCiphertext)->toBe('cipher-two')->and($second->isActive())->toBeFalse();
});

it('says whether the pending secret is fresh enough to confirm', function () {
    $factor = pendingFactor();

    expect($factor->hasFreshPending(Identity::now()->modify('+15 minutes'), TotpFactor::PENDING_LIFETIME_SECONDS))->toBeTrue()
        ->and($factor->hasFreshPending(Identity::now()->modify('+15 minutes +1 second'), TotpFactor::PENDING_LIFETIME_SECONDS))->toBeFalse()
        ->and($factor->confirmPending(1, Identity::now())->hasFreshPending(Identity::now(), 900))->toBeFalse();
});

it('records the accepted time step, so a code cannot be replayed', function () {
    $active = pendingFactor()->confirmPending(10, Identity::now())->withStepUsed(11, Identity::now());

    expect($active->lastUsedStep)->toBe(11);
});

it('refuses to exist in an inconsistent state', function () {
    $now = Identity::now();
    $id = TotpFactorId::generate();
    $account = AccountId::generate();

    expect(fn () => TotpFactor::reconstitute($id, $account, null, null, null, null, null, $now, $now))->toThrow(InvalidAccountState::class)
        ->and(fn () => TotpFactor::reconstitute($id, $account, 'c', null, null, null, null, $now, $now))->toThrow(InvalidAccountState::class)
        ->and(fn () => TotpFactor::reconstitute($id, $account, null, 'p', null, null, null, $now, $now))->toThrow(InvalidAccountState::class);
});
