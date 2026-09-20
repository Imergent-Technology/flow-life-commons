<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\AccountRecord;
use Illuminate\Contracts\Auth\Authenticatable;
use Tests\Support\Identity;

/*
 * The seam only: Account can be handed to Laravel's authentication machinery. No guard,
 * provider registration, login endpoint or session exists in this phase.
 */

function persistedAccountRecord(): AccountRecord
{
    $account = Identity::savedInvitedAccount();
    $record = AccountRecord::query()->findOrFail($account->id->value);
    $record->password_hash = '$2y$12$examplehashexamplehashexamplehashexamplehashexamplehas';

    return $record;
}

it('is a Laravel Authenticatable identified by its ULID', function () {
    $record = persistedAccountRecord();

    expect($record)->toBeInstanceOf(Authenticatable::class)
        ->and($record->getAuthIdentifierName())->toBe('id')
        ->and($record->getAuthIdentifier())->toBe($record->id)
        ->and($record->getAuthIdentifier())->toHaveLength(26);
});

it('exposes password_hash as the credential', function () {
    $record = persistedAccountRecord();

    expect($record->getAuthPasswordName())->toBe('password_hash')
        ->and($record->getAuthPassword())->toBe($record->password_hash);
});

it('has no remember-me token', function () {
    $record = persistedAccountRecord();
    $record->setRememberToken('anything');

    expect($record->getRememberTokenName())->toBe('')
        ->and($record->getRememberToken())->toBeNull()
        ->and($record->getAttributes())->not->toHaveKey('remember_token');
});

it('never serialises the password hash', function () {
    $record = persistedAccountRecord();

    expect($record->toArray())->not->toHaveKey('password_hash')
        ->and($record->toJson())->not->toContain('examplehash');
});
