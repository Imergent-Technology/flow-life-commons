<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\EmailAddress;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;

$now = new DateTimeImmutable('2026-09-19 12:00:00');
$later = new DateTimeImmutable('2026-09-19 13:00:00');

function invitedAccount(DateTimeImmutable $now): Account
{
    return Account::invite(AccountId::generate(), PersonId::generate(), EmailAddress::fromString('a@example.org'), $now);
}

it('verifies the email once, keeping the earliest evidence', function () use ($now, $later) {
    $verified = invitedAccount($now)->verifyEmail($now);

    expect($verified->emailVerifiedAt)->toEqual($now)
        ->and($verified->verifyEmail($later)->emailVerifiedAt)->toEqual($now);
});

it('enables a disabled Account that has a credential back to active, keeping its credential and verification', function () use ($now, $later) {
    $disabled = invitedAccount($now)->activate('hash', $now)->verifyEmail($now)->disable($later);

    $enabled = $disabled->enable($later->modify('+1 hour'));

    expect($enabled->status)->toBe(AccountStatus::Active)
        ->and($enabled->passwordHash)->toBe('hash')
        ->and($enabled->disabledAt)->toBeNull()
        ->and($enabled->emailVerifiedAt)->toEqual($now)
        ->and($enabled->canAuthenticate())->toBeTrue();
});

it('enables a disabled Account with no credential back to invited', function () use ($now, $later) {
    $enabled = invitedAccount($now)->disable($later)->enable($later);

    expect($enabled->status)->toBe(AccountStatus::Invited)->and($enabled->canAuthenticate())->toBeFalse();
});
