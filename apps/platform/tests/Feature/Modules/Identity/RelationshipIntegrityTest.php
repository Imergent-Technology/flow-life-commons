<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\PersonAlreadyHasAccount;
use App\Modules\Identity\Domain\PersonRepository;
use App\Modules\Identity\Infrastructure\Persistence\AccountInvitationRecord;
use App\Modules\Identity\Infrastructure\Persistence\AccountRecord;
use App\Modules\Identity\Infrastructure\Persistence\PersonRecord;
use App\Shared\Domain\AccountId;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Identity;

/*
 * ADR 0021: foreign keys within Identity use RESTRICT, never CASCADE, and provenance
 * references carry no foreign key at all. "Refused" is asserted on real rows: after a
 * refused delete the data must still be there.
 */

it('allows at most one Account per Person', function () {
    $person = Identity::savedPerson();
    app(AccountRepository::class)->save(Identity::invitedAccount($person, 'first@example.org'));

    $error = Identity::violation(
        fn () => app(AccountRepository::class)->save(Identity::invitedAccount($person, 'second@example.org')),
    );

    expect($error)->toBeInstanceOf(PersonAlreadyHasAccount::class)
        ->and(DB::table('accounts')->count())->toBe(1);
});

it('holds one-Account-per-Person in the database itself, not only in PHP', function () {
    $account = Identity::savedInvitedAccount('first@example.org');

    $error = Identity::violation(fn () => AccountRecord::query()->create([
        'id' => AccountId::generate()->value,
        'person_id' => $account->personId->value,
        'email' => 'second@example.org',
        'email_canonical' => 'second@example.org',
        'status' => 'invited',
        'created_at' => Identity::now(),
        'updated_at' => Identity::now(),
    ]));

    expect($error)->toBeInstanceOf(UniqueConstraintViolationException::class);
});

it('refuses an Account whose Person does not exist', function () {
    $orphan = Identity::invitedAccount(Identity::person());

    $error = Identity::violation(fn () => app(AccountRepository::class)->save($orphan));

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and(DB::table('accounts')->count())->toBe(0);
});

it('refuses to delete a Person who has an Account (RESTRICT, not CASCADE)', function () {
    $account = Identity::savedInvitedAccount();

    $error = Identity::violation(fn () => PersonRecord::query()->findOrFail($account->personId->value)->delete());

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and(DB::table('people')->count())->toBe(1)
        ->and(DB::table('accounts')->count())->toBe(1);
});

it('refuses to delete an Account that has invitations (RESTRICT, not CASCADE)', function () {
    $account = Identity::savedInvitedAccount();
    Identity::savedInvitation($account);

    $error = Identity::violation(fn () => AccountRecord::query()->findOrFail($account->id->value)->delete());

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and(DB::table('accounts')->count())->toBe(1)
        ->and(DB::table('account_invitations')->count())->toBe(1);
});

it('allows removal once the dependents are removed first, as an explicit step', function () {
    $account = Identity::savedInvitedAccount();
    $invitation = Identity::savedInvitation($account);

    AccountInvitationRecord::query()->findOrFail($invitation->id->value)->delete();
    AccountRecord::query()->findOrFail($account->id->value)->delete();
    PersonRecord::query()->findOrFail($account->personId->value)->delete();

    expect(DB::table('account_invitations')->count())->toBe(0)
        ->and(DB::table('accounts')->count())->toBe(0)
        ->and(DB::table('people')->count())->toBe(0);
});

it('lets a Person without an Account be deleted', function () {
    $person = Identity::savedPerson();

    PersonRecord::query()->findOrFail($person->id->value)->delete();

    expect(app(PersonRepository::class)->find($person->id))->toBeNull();
});

it('refuses an invitation for an Account that does not exist', function () {
    $orphan = Identity::invitation(Identity::invitedAccount(Identity::person()));

    $error = Identity::violation(fn () => app(AccountInvitationRepository::class)->save($orphan));

    expect($error)->toBeInstanceOf(QueryException::class)
        ->and(DB::table('account_invitations')->count())->toBe(0);
});

it('keeps invited_by_account_id as provenance with no foreign key', function () {
    $account = Identity::savedInvitedAccount();
    $vanished = AccountId::generate(); // no such Account exists
    $invitation = Identity::invitation($account, null, $vanished);

    app(AccountInvitationRepository::class)->save($invitation);

    expect(app(AccountInvitationRepository::class)->find($invitation->id)?->invitedByAccountId)->toEqual($vanished);
});

it('does not let an inviter be blocked from removal by invitations they sent', function () {
    $inviter = Identity::savedInvitedAccount('inviter@example.org');
    $invitee = Identity::savedInvitedAccount('invitee@example.org');
    Identity::savedInvitation($invitee); // referenced only as provenance below
    app(AccountInvitationRepository::class)->save(Identity::invitation($invitee, null, $inviter->id));

    // The inviter has no invitations of their own, and the ones they sent do not reference
    // them by foreign key, so removing them must not be blocked.
    AccountRecord::query()->findOrFail($inviter->id->value)->delete();
    PersonRecord::query()->findOrFail($inviter->personId->value)->delete();

    expect(DB::table('account_invitations')->count())->toBe(2)
        ->and(DB::table('accounts')->count())->toBe(1);
});

it('introduces no foreign keys beyond its own tables', function () {
    // No cross-module reference exists in this phase (ADR 0021 permits them only for
    // fundamental invariants, and Access does not exist yet).
    $targets = [];
    foreach (['people', 'accounts', 'account_invitations'] as $table) {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            assert(is_array($foreignKey));
            assert(is_string($foreignKey['foreign_table']));
            $targets[] = "{$table} -> {$foreignKey['foreign_table']}";
        }
    }

    expect($targets)->toEqualCanonicalizing(['accounts -> people', 'account_invitations -> accounts']);
});
