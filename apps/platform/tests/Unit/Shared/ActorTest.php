<?php

declare(strict_types=1);

use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\AuthenticationMethod;
use App\Shared\Domain\PersonId;

it('is a signed-in human: identity and provenance', function () {
    $account = AccountId::generate();
    $person = PersonId::generate();

    $actor = Actor::user($account, $person);

    expect($actor->accountId)->toBe($account)
        ->and($actor->personId)->toBe($person)
        ->and($actor->authenticatedVia)->toBe(AuthenticationMethod::Session);
});

it('carries no capabilities, roles or other snapshot of privilege', function () {
    // A captured Actor must never preserve revoked privileges: authorization is always
    // evaluated against current persisted state. Adding a field here is a design decision.
    $properties = array_map(fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(Actor::class))->getProperties());

    expect($properties)->toEqualCanonicalizing(['accountId', 'personId', 'authenticatedVia']);
});

it('has no anonymous or system form: the shipped shape is user() only', function () {
    $factories = array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(Actor::class))->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC),
    );

    expect($factories)->toBe(['user']);
});

it('is immutable, so it cannot be upgraded after the fact', function () {
    $actor = Actor::user(AccountId::generate(), PersonId::generate());

    expect(fn () => (new ReflectionProperty($actor, 'accountId'))->setValue($actor, AccountId::generate()))
        ->toThrow(Error::class, 'readonly');
});
