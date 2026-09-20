<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Auth\AccountUserProvider;
use App\Modules\Identity\Infrastructure\Persistence\AccountRecord;
use App\Shared\Domain\AccountId;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Identity;

function provider(): AccountUserProvider
{
    return app(AccountUserProvider::class);
}

it('is the provider behind the configured guard', function () {
    expect(config('auth.guards.web.provider'))->toBe('accounts')
        ->and(config('auth.providers.accounts.driver'))->toBe('identity')
        ->and(Auth::createUserProvider('accounts'))->toBeInstanceOf(AccountUserProvider::class);
});

it('resolves an active account by id', function () {
    $account = Identity::savedActiveAccount();

    $user = provider()->retrieveById($account->id->value);

    expect($user)->toBeInstanceOf(AccountRecord::class)->and($user?->getAuthIdentifier())->toBe($account->id->value);
});

it('resolves nothing for an invited, disabled, missing or malformed id', function () {
    $invited = Identity::savedInvitedAccount('invited@example.org');
    $disabled = Identity::savedDisabledAccount('disabled@example.org');

    expect(provider()->retrieveById($invited->id->value))->toBeNull()
        ->and(provider()->retrieveById($disabled->id->value))->toBeNull()
        ->and(provider()->retrieveById(AccountId::generate()->value))->toBeNull()
        ->and(provider()->retrieveById('not-a-ulid'))->toBeNull()
        ->and(provider()->retrieveById(42))->toBeNull();
});

it('finds accounts by credentials through the canonical email', function (string $typed) {
    $account = Identity::savedActiveAccount('Ada.Lovelace@Example.org');

    expect(provider()->retrieveByCredentials(['email' => $typed, 'password' => 'x'])?->getAuthIdentifier())->toBe($account->id->value);
})->with(['ada.lovelace@example.org', 'ADA.LOVELACE@EXAMPLE.ORG', ' Ada.Lovelace@Example.org ']);

it('finds nothing for credentials with a malformed or unknown email, or a non-active account', function () {
    Identity::savedInvitedAccount('invited@example.org');

    expect(provider()->retrieveByCredentials(['email' => 'nobody@example.org']))->toBeNull()
        ->and(provider()->retrieveByCredentials(['email' => 'not an email']))->toBeNull()
        ->and(provider()->retrieveByCredentials(['email' => 42]))->toBeNull()
        ->and(provider()->retrieveByCredentials([]))->toBeNull()
        ->and(provider()->retrieveByCredentials(['email' => 'invited@example.org']))->toBeNull();
});

it('validates only the right password of an eligible account', function () {
    Identity::savedActiveAccount('ada@example.org');
    $user = provider()->retrieveByCredentials(['email' => 'ada@example.org']);
    assert($user !== null);

    expect(provider()->validateCredentials($user, ['password' => Identity::PASSWORD]))->toBeTrue()
        ->and(provider()->validateCredentials($user, ['password' => 'wrong']))->toBeFalse()
        ->and(provider()->validateCredentials($user, []))->toBeFalse();
});

it('supports no remember-me', function () {
    $account = Identity::savedActiveAccount();
    $user = provider()->retrieveById($account->id->value);
    assert($user !== null);

    expect(provider()->retrieveByToken($account->id->value, 'anything'))->toBeNull();
    provider()->updateRememberToken($user, 'anything');
    expect($user->getRememberToken())->toBeNull();
});
