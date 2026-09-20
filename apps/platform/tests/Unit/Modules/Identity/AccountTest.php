<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvalidAccountState;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Tests\Support\Identity;

function invited(): Account
{
    return Identity::invitedAccount(Identity::person());
}

it('is created by invitation: invited, with no credential', function () {
    $account = invited();

    expect($account->status)->toBe(AccountStatus::Invited)
        ->and($account->passwordHash)->toBeNull()
        ->and($account->passwordUpdatedAt)->toBeNull()
        ->and($account->emailVerifiedAt)->toBeNull()
        ->and($account->disabledAt)->toBeNull()
        ->and($account->lastLoginAt)->toBeNull();
});

it('has exactly the frozen statuses', function () {
    expect(array_map(fn (AccountStatus $s): string => $s->value, AccountStatus::cases()))
        ->toBe(['invited', 'active', 'disabled']);
});

it('becomes active on acceptance, which sets the password and records the address as accepted', function () {
    $later = Identity::now()->modify('+1 hour');
    $active = invited()->activate('$2y$hash', $later);

    expect($active->status)->toBe(AccountStatus::Active)
        ->and($active->passwordHash)->toBe('$2y$hash')
        ->and($active->passwordUpdatedAt)->toEqual($later)
        ->and($active->emailVerifiedAt)->toEqual($later)
        ->and($active->updatedAt)->toEqual($later)
        ->and($active->createdAt)->toEqual(Identity::now());
});

it('cannot be activated unless it is invited', function () {
    $active = invited()->activate('$2y$hash', Identity::now());

    $active->activate('$2y$other', Identity::now());
})->throws(InvalidAccountState::class);

it('can be disabled from either live state, keeping its history', function () {
    $invited = invited();
    $active = invited()->activate('$2y$hash', Identity::now());
    $later = Identity::now()->modify('+2 hours');

    expect($invited->disable($later)->status)->toBe(AccountStatus::Disabled)
        ->and($invited->disable($later)->disabledAt)->toEqual($later)
        ->and($active->disable($later)->passwordHash)->toBe('$2y$hash')
        ->and($active->disable($later)->emailVerifiedAt)->toEqual(Identity::now());
});

it('cannot be disabled twice, or re-activated once disabled', function () {
    $disabled = invited()->disable(Identity::now());

    expect(fn () => $disabled->disable(Identity::now()))->toThrow(InvalidAccountState::class)
        ->and(fn () => $disabled->activate('$2y$hash', Identity::now()))->toThrow(InvalidAccountState::class);
});

it('transitions return new instances and leave the original untouched', function () {
    $invited = invited();
    $invited->activate('$2y$hash', Identity::now());

    expect($invited->status)->toBe(AccountStatus::Invited)->and($invited->passwordHash)->toBeNull();
});

it('refuses to be reconstituted in an inconsistent state', function (AccountStatus $status, ?string $hash, bool $disabled) {
    $now = Identity::now();

    Account::reconstitute(
        AccountId::generate(), PersonId::generate(), EmailAddress::fromString('a@example.org'), $status,
        $hash, $hash === null ? null : $now, null, $disabled ? $now : null, null, $now, $now,
    );
})->with([
    'invited with a password' => [AccountStatus::Invited, 'hash', false],
    'invited but disabled' => [AccountStatus::Invited, null, true],
    'active without a password' => [AccountStatus::Active, null, false],
    'active but disabled' => [AccountStatus::Active, 'hash', true],
    'disabled without a disabled time' => [AccountStatus::Disabled, null, false],
])->throws(InvalidAccountState::class);

it('keeps the password hash and its update time together', function () {
    $now = Identity::now();

    Account::reconstitute(
        AccountId::generate(), PersonId::generate(), EmailAddress::fromString('a@example.org'), AccountStatus::Active,
        'hash', null, null, null, null, $now, $now,
    );
})->throws(InvalidAccountState::class);

it('may authenticate only when active with a credential', function () {
    $invited = invited();
    $active = invited()->activate('$2y$hash', Identity::now());
    $disabled = $active->disable(Identity::now());

    expect($invited->canAuthenticate())->toBeFalse()
        ->and($active->canAuthenticate())->toBeTrue()
        ->and($disabled->canAuthenticate())->toBeFalse()
        // Disabling an account that never activated leaves it unable to authenticate too.
        ->and(invited()->disable(Identity::now())->canAuthenticate())->toBeFalse();
});

it('records a login without treating it as a profile change', function () {
    $active = invited()->activate('$2y$hash', Identity::now());
    $later = Identity::now()->modify('+1 day');

    $loggedIn = $active->recordLogin($later);

    expect($loggedIn->lastLoginAt)->toEqual($later)
        ->and($loggedIn->updatedAt)->toEqual($active->updatedAt)
        ->and($loggedIn->status)->toBe($active->status)
        ->and($active->lastLoginAt)->toBeNull();
});

it('cannot record a login unless it may authenticate', function () {
    invited()->recordLogin(Identity::now());
})->throws(InvalidAccountState::class);

it('replaces its credential only when it can sign in, and changes nothing else', function () {
    $active = invited()->activate('$2y$old', Identity::now())->recordLogin(Identity::now()->modify('+1 hour'));
    $later = Identity::now()->modify('+2 hours');

    $changed = $active->changePassword('$2y$new', $later);

    expect($changed->passwordHash)->toBe('$2y$new')
        ->and($changed->passwordUpdatedAt)->toEqual($later)
        ->and($changed->updatedAt)->toEqual($later)
        ->and($changed->status)->toBe(AccountStatus::Active)
        // Everything else is as it was: not a sign-in, not a re-verification, not a re-enabling.
        ->and($changed->lastLoginAt)->toEqual($active->lastLoginAt)
        ->and($changed->emailVerifiedAt)->toEqual($active->emailVerifiedAt)
        ->and($changed->disabledAt)->toBeNull()
        ->and($active->passwordHash)->toBe('$2y$old');
});

it('never activates an invited account or re-enables a disabled one by changing a password', function () {
    $active = invited()->activate('$2y$old', Identity::now());

    expect(fn () => invited()->changePassword('$2y$new', Identity::now()))->toThrow(InvalidAccountState::class)
        ->and(fn () => $active->disable(Identity::now()->modify('+1 hour'))->changePassword('$2y$new', Identity::now()->modify('+2 hours')))
        ->toThrow(InvalidAccountState::class);
});
