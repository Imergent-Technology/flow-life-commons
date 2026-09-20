<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\EmailAddressAlreadyInUse;
use App\Modules\Identity\Infrastructure\Persistence\AccountRecord;
use App\Shared\Domain\AccountId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

/*
 * ADR 0005 / ADR 0015: a plain unique(email) behaves differently on the two engines, so
 * uniqueness and lookup use `email_canonical`. These tests run on whichever engine the
 * suite is pointed at; `./flow check --pgsql` runs the same file on both.
 */

/** A second, different Person's Account for the given address. */
function accountFor(string $email): Account
{
    return Identity::invitedAccount(Identity::savedPerson('Another Person'), $email);
}

it('cannot create a duplicate Account from any case variant of an address', function (string $variant) {
    Identity::savedInvitedAccount('person@example.org');

    $error = Identity::violation(fn () => app(AccountRepository::class)->save(accountFor($variant)));

    expect($error)->toBeInstanceOf(EmailAddressAlreadyInUse::class)
        ->and(DB::table('accounts')->count())->toBe(1);
})->with([
    'identical' => 'person@example.org',
    'capitalised' => 'Person@Example.org',
    'upper case' => 'PERSON@EXAMPLE.ORG',
    'mixed case' => 'pErSoN@eXaMpLe.OrG',
    'padded' => '  person@example.org ',
]);

it('holds the case-insensitive uniqueness in the database itself, not only in PHP', function () {
    Identity::savedInvitedAccount('person@example.org');
    $other = Identity::savedPerson('Another Person');

    // Bypass the domain and repository entirely: the constraint must still refuse it.
    $error = Identity::violation(fn () => AccountRecord::query()->create([
        'id' => AccountId::generate()->value,
        'person_id' => $other->id->value,
        'email' => 'PERSON@EXAMPLE.ORG',
        'email_canonical' => 'person@example.org',
        'status' => 'invited',
        'created_at' => Identity::now(),
        'updated_at' => Identity::now(),
    ]));

    expect($error)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and(DB::table('accounts')->count())->toBe(1);
});

it('looks an Account up by any case variant of its address', function (string $variant) {
    $account = Identity::savedInvitedAccount('Person@Example.org');

    expect(app(AccountRepository::class)->findByEmail(EmailAddress::fromString($variant)))->toEqual($account);
})->with(['person@example.org', 'PERSON@EXAMPLE.ORG', 'Person@Example.org', ' person@example.org ']);

it('finds nothing for an address nobody holds', function () {
    Identity::savedInvitedAccount('person@example.org');

    expect(app(AccountRepository::class)->findByEmail(EmailAddress::fromString('other@example.org')))->toBeNull();
});

it('keeps genuinely different addresses distinct on this engine', function () {
    // Neither engine may over-match: MariaDB's utf8mb4_unicode_ci must not fold
    // punctuation, and no provider-specific rewriting (dots, +tags) may creep in.
    $addresses = ['a.b@example.org', 'ab@example.org', 'a+b@example.org', 'a-b@example.org', 'a_b@example.org'];

    foreach ($addresses as $address) {
        Identity::savedInvitedAccount($address);
    }

    expect(DB::table('accounts')->count())->toBe(count($addresses))
        ->and(DB::table('accounts')->pluck('email_canonical')->all())->toEqualCanonicalizing($addresses);
});

it('does not use the email as the identity: it can change while the Account stays the same', function () {
    $account = Identity::savedInvitedAccount('old@example.org');

    // Email change is a later use case; this pins the schema property it relies on.
    AccountRecord::query()->findOrFail($account->id->value)
        ->update(['email' => 'New@Example.org', 'email_canonical' => 'new@example.org']);

    $repository = app(AccountRepository::class);

    expect($repository->findByEmail(EmailAddress::fromString('old@example.org')))->toBeNull()
        ->and($repository->findByEmail(EmailAddress::fromString('NEW@example.org'))?->id)->toEqual($account->id)
        ->and($repository->find($account->id)?->personId)->toEqual($account->personId);

    // ...and the new address is now the one that is protected.
    $error = Identity::violation(fn () => $repository->save(accountFor('new@EXAMPLE.org')));
    expect($error)->toBeInstanceOf(EmailAddressAlreadyInUse::class);
});

it('shows why a canonical column is needed: a plain unique varchar is engine-dependent', function () {
    // The measured divergence behind ADR 0015, reproduced on the real schema with no DDL:
    // account_invitations.token_hash is a unique varchar with the default collation.
    // MariaDB (utf8mb4_unicode_ci) treats 'AB…' and 'ab…' as the same value; PostgreSQL
    // does not. If this test starts failing, the engines' behaviour changed and the
    // premise of email_canonical needs revisiting.
    $account = Identity::savedInvitedAccount();
    $insert = fn (string $hash) => DB::table('account_invitations')->insert([
        'id' => AccountId::generate()->value,
        'account_id' => $account->id->value,
        'token_hash' => $hash,
        'expires_at' => Identity::now(),
    ]);

    $insert(str_repeat('AB', 32));
    $second = null;
    try {
        Identity::violation(fn () => $insert(str_repeat('ab', 32)));
    } catch (RuntimeException $e) {
        $second = 'accepted';
    }

    $driver = DB::connection()->getDriverName();

    expect($second)->toBe($driver === 'pgsql' ? 'accepted' : null)
        ->and(DB::table('account_invitations')->count())->toBe($driver === 'pgsql' ? 2 : 1);
});
