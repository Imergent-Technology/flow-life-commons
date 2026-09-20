<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\AccountInvitationId;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use App\Shared\Domain\UlidIdentifier;

it('generates 26-character lowercase ULIDs', function () {
    $id = PersonId::generate();

    expect($id->value)->toHaveLength(26)
        ->and($id->value)->toBe(strtolower($id->value))
        ->and(PersonId::generate()->value)->not->toBe($id->value);
});

it('generates time-sortable identifiers', function () {
    $first = PersonId::generate();
    usleep(2000);
    $second = PersonId::generate();

    expect(strcmp($first->value, $second->value))->toBeLessThan(0);
});

it('normalises to lowercase, because CHAR comparison is case-insensitive on MariaDB only', function () {
    $id = AccountId::generate();

    expect(AccountId::fromString(strtoupper($id->value))->value)->toBe($id->value)
        ->and(AccountId::fromString(strtoupper($id->value))->equals($id))->toBeTrue();
});

it('rejects values that are not ULIDs', function (string $value) {
    PersonId::fromString($value);
})->with(['', 'not-a-ulid', '01ARZ3NDEKTSV4RRFFQ69G5FA', '01ARZ3NDEKTSV4RRFFQ69G5FAVX', '81ARZ3NDEKTSV4RRFFQ69G5FAV', '01ARZ3NDEKTSV4RRFFQ69G5FAU'])
    ->throws(InvalidArgumentException::class);

it('never treats identifiers of different kinds as equal', function () {
    $person = PersonId::generate();
    $account = AccountId::fromString($person->value);

    expect($person->equals($account))->toBeFalse()
        ->and(AccountInvitationId::generate())->toBeInstanceOf(UlidIdentifier::class);
});
