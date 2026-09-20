<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\AccountStatus;
use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Identity;

it('creates and reloads a Person', function () {
    $person = Identity::savedPerson('  Ada Lovelace ');

    $found = app(PersonRepository::class)->find($person->id);

    expect($found)->toBeInstanceOf(Person::class)
        ->and($found?->displayName)->toBe('Ada Lovelace')
        ->and($found?->createdAt)->toEqual(Identity::now())
        ->and(DB::table('people')->count())->toBe(1);
});

it('returns null for a Person that does not exist', function () {
    expect(app(PersonRepository::class)->find(PersonId::generate()))->toBeNull();
});

it('creates an Account for a Person and reloads it faithfully', function () {
    $account = Identity::savedInvitedAccount('Ada.Lovelace@Example.org');

    $found = app(AccountRepository::class)->find($account->id);

    expect($found)->toEqual($account)
        ->and($found?->email->value)->toBe('Ada.Lovelace@Example.org')
        ->and($found?->status)->toBe(AccountStatus::Invited);
});

it('stores the email as entered and the canonical form separately', function () {
    Identity::savedInvitedAccount('Ada.Lovelace@Example.org');

    $row = DB::table('accounts')->first();

    expect($row?->email)->toBe('Ada.Lovelace@Example.org')
        ->and($row?->email_canonical)->toBe('ada.lovelace@example.org');
});

it('persists and reloads every account status', function (AccountStatus $status) {
    $repository = app(AccountRepository::class);
    $later = Identity::now()->modify('+1 hour');

    $account = Identity::invitedAccount(Identity::savedPerson());
    $account = match ($status) {
        AccountStatus::Invited => $account,
        AccountStatus::Active => $account->activate('$2y$12$hash', $later),
        AccountStatus::Disabled => $account->activate('$2y$12$hash', $later)->disable($later->modify('+1 hour')),
    };
    $repository->save($account);

    $found = $repository->find($account->id);

    expect($found)->toEqual($account)
        ->and($found?->status)->toBe($status)
        ->and(DB::table('accounts')->value('status'))->toBe($status->value);
})->with(AccountStatus::cases());

it('updates an existing Account rather than inserting another', function () {
    $repository = app(AccountRepository::class);
    $account = Identity::savedInvitedAccount();

    $repository->save($account->activate('$2y$12$hash', Identity::now()->modify('+1 hour')));

    expect(DB::table('accounts')->count())->toBe(1)
        ->and($repository->find($account->id)?->status)->toBe(AccountStatus::Active);
});

it('finds an Account by its person', function () {
    $account = Identity::savedInvitedAccount();

    expect(app(AccountRepository::class)->findByPersonId($account->personId))->toEqual($account)
        ->and(app(AccountRepository::class)->findByPersonId(PersonId::generate()))->toBeNull();
});

it('lets a Person exist without an Account', function () {
    $person = Identity::savedPerson();

    expect(app(AccountRepository::class)->findByPersonId($person->id))->toBeNull();
});

it('stores instants in UTC and reads them back as the same instant', function () {
    // 12:00 at UTC+10 is 02:00 UTC. Eloquent formats a date in the zone it carries,
    // so without explicit conversion the wall-clock 12:00 would be stored.
    $local = new DateTimeImmutable('2026-09-19 12:00:00', new DateTimeZone('+10:00'));
    $person = Person::create(PersonId::generate(), 'Ada', $local);
    app(PersonRepository::class)->save($person);

    $row = DB::table('people')->first();
    $found = app(PersonRepository::class)->find($person->id);

    expect($row?->created_at)->toBe('2026-09-19 02:00:00')
        ->and($found?->createdAt->getTimestamp())->toBe($local->getTimestamp())
        ->and($found?->createdAt->getTimezone()->getName())->toBe('UTC');
});

it('stores ULIDs as exactly 26 lowercase characters and finds them by any case', function () {
    $account = Identity::savedInvitedAccount();

    $row = DB::table('accounts')->first();
    $id = $row?->id;
    assert(is_string($id));

    expect($id)->toHaveLength(26)->toBe(strtolower($id))
        ->and($row->person_id)->toHaveLength(26)
        // MariaDB matches CHAR case-insensitively and PostgreSQL does not; the id value
        // object normalises so both engines find the row.
        ->and(app(AccountRepository::class)->find(AccountId::fromString(strtoupper($account->id->value))))->toEqual($account);
});

/**
 * @return array{string, string} the engine's type name and full type for a column
 */
function columnType(string $table, string $column): array
{
    foreach (Schema::getColumns($table) as $info) {
        assert(is_array($info));

        if ($info['name'] === $column) {
            assert(is_string($info['type_name']) && is_string($info['type']));

            return [$info['type_name'], $info['type']];
        }
    }

    throw new RuntimeException("No column {$table}.{$column}.");
}

it('uses fixed-width 26-character ULID keys and portable string statuses', function () {
    [$idName, $idType] = columnType('accounts', 'id');
    [, $personType] = columnType('accounts', 'person_id');
    [$statusName, $statusType] = columnType('accounts', 'status');

    // The same fixed-width type, named `char` by MariaDB and `bpchar` by PostgreSQL;
    // the full type carries the width.
    expect($idName)->toBeIn(['char', 'bpchar'])
        ->and($idType)->toContain('26')
        ->and($personType)->toContain('26')
        ->and($statusName)->toBeIn(['varchar', 'character varying'])
        ->and($statusType)->not->toContain('enum');
});
