<?php

declare(strict_types=1);

use App\Modules\Identity\Application\RegisterPerson;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function registerPerson(string $displayName = 'Mia Member'): Person
{
    return app(RegisterPerson::class)($displayName);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-23 12:00:00');
});

it('creates a Person with the given display name', function () {
    $person = registerPerson('  Mia Member ');

    expect($person->displayName)->toBe('Mia Member')
        ->and(DB::table('people')->where('id', $person->id->value)->value('display_name'))->toBe('Mia Member');
});

it('generates a ULID identity the same way every other aggregate does', function () {
    $person = registerPerson();

    expect($person->id)->toBeInstanceOf(PersonId::class)
        ->and($person->id->value)->toHaveLength(26)
        ->and($person->id->value)->toBe(strtolower($person->id->value));
});

it('is found through the normal Identity repository path', function () {
    $person = registerPerson('Mia Member');

    $found = app(PersonRepository::class)->find($person->id);

    expect($found)->toEqual($person)
        ->and($found?->displayName)->toBe('Mia Member');
});

it('creates no Account for the new Person', function () {
    $person = registerPerson();

    expect(app(AccountRepository::class)->findByPersonId($person->id))->toBeNull()
        ->and(DB::table('accounts')->count())->toBe(0);
});

it('creates no account invitation', function () {
    registerPerson();

    expect(DB::table('account_invitations')->count())->toBe(0);
});

it('creates no role assignment', function () {
    registerPerson();

    expect(DB::table('role_assignments')->count())->toBe(0);
});

it('writes no security event merely because a Person was created', function () {
    registerPerson();

    expect(DB::table('security_events')->count())->toBe(0);
});

it('creates two distinct People for two calls, even with the same display name', function () {
    $first = registerPerson('Mia Member');
    $second = registerPerson('Mia Member');

    expect($first->id->equals($second->id))->toBeFalse()
        ->and(DB::table('people')->count())->toBe(2);
});

it('enforces the same display-name validation Person already enforces', function () {
    expect(fn () => registerPerson(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => registerPerson(str_repeat('a', Person::MAX_DISPLAY_NAME_LENGTH + 1)))->toThrow(InvalidArgumentException::class);

    expect(DB::table('people')->count())->toBe(0);
});

it('participates in an outer transaction and commits with it', function () {
    $person = null;

    DB::transaction(function () use (&$person) {
        $person = registerPerson('Mia Member');
    });
    assert($person instanceof Person);

    expect(app(PersonRepository::class)->find($person->id))->not->toBeNull();
});

it('rolls back with an outer transaction that fails after it succeeds', function () {
    $personId = null;

    try {
        DB::transaction(function () use (&$personId) {
            $person = registerPerson('Mia Member');
            $personId = $person->id;

            throw new RuntimeException('simulated failure after registration');
        });
    } catch (RuntimeException) {
        // expected: the outer transaction was made to fail on purpose
    }
    assert($personId instanceof PersonId);

    expect(app(PersonRepository::class)->find($personId))->toBeNull()
        ->and(DB::table('people')->count())->toBe(0);
});

it('stores the created instant in UTC, from the frozen clock', function () {
    $person = registerPerson();

    expect($person->createdAt)->toEqual(new DateTimeImmutable('2026-09-23 12:00:00', new DateTimeZone('UTC')))
        ->and(DB::table('people')->where('id', $person->id->value)->value('created_at'))->toBe('2026-09-23 12:00:00');
});
