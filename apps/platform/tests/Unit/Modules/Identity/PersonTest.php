<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Person;
use App\Shared\Domain\PersonId;
use Tests\Support\Identity;

it('is an id, a display name and timestamps, and nothing else', function () {
    $person = Identity::person('  Ada Lovelace ');

    expect($person->displayName)->toBe('Ada Lovelace')
        ->and($person->createdAt)->toEqual(Identity::now())
        ->and($person->updatedAt)->toEqual(Identity::now());

    // Person must stay a thin anchor (ADR 0015). Adding a property here is a design
    // decision, not a convenience: contact and profile data belong to CRM, keyed by person_id.
    $properties = array_map(
        fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(Person::class))->getProperties(),
    );
    expect($properties)->toEqualCanonicalizing(['id', 'displayName', 'createdAt', 'updatedAt']);
});

it('requires a display name of 1 to 255 characters', function (string $name) {
    Person::create(PersonId::generate(), $name, Identity::now());
})->with(['empty' => '', 'blank' => '   ', 'too long' => str_repeat('x', 256)])
    ->throws(InvalidArgumentException::class);

it('accepts multibyte names up to the limit', function () {
    $name = str_repeat('é', 255);

    expect(Person::create(PersonId::generate(), $name, Identity::now())->displayName)->toBe($name);
});
