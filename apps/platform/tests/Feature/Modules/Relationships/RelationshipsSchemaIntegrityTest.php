<?php

declare(strict_types=1);

use App\Shared\Domain\PersonId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\Api;
use Tests\Support\Identity;

/*
 * What the Relationships schema guarantees whatever the application does (ADR 0038, F6, T-rows): a Person has at most one relationship
 * of a type. The use cases check it, and the race suite proves two concurrent requests end with one row; this file is the deterministic
 * floor under both, the database's own refusal, so dropping the unique index fails here by name and not only under a race. Runs on
 * MariaDB and PostgreSQL (`./flow test backend --pgsql`).
 */

const PERSON_TYPE_UNIQUE = 'person_relationships_person_type_unique';

/** @return array<string, mixed> */
function relationshipRow(PersonId $person, string $type): array
{
    return [
        'id' => strtolower((string) Str::ulid()), 'person_id' => $person->value, 'relationship_type' => $type, 'status' => 'active', 'revision' => 1,
        'status_changed_at' => '2026-10-01 12:00:00', 'status_changed_by' => $person->value,
        'created_at' => '2026-10-01 12:00:00', 'created_by' => $person->value, 'updated_at' => '2026-10-01 12:00:00', 'updated_by' => $person->value,
    ];
}

it('declares the unique index on a Person and a relationship type, by name, on both engines', function () {
    $found = null;
    foreach (Api::rows(Schema::getIndexes('person_relationships')) as $index) {
        if ($index['name'] === PERSON_TYPE_UNIQUE) {
            $found = $index;
        }
    }

    expect($found)->not->toBeNull()
        ->and($found['unique'] ?? null)->toBeTrue()
        ->and($found['primary'] ?? null)->toBeFalse()
        ->and(Api::strings($found['columns'] ?? []))->toBe(['person_id', 'relationship_type']);
});

it('refuses a second relationship of the same type for one Person, in the database, and names the index', function () {
    $ada = Identity::savedPerson('Ada');
    DB::table('person_relationships')->insert(relationshipRow($ada->id, 'guardian'));

    $refusal = null;
    try {
        // Inside its own savepoint: on PostgreSQL a failed statement aborts the surrounding transaction.
        DB::transaction(fn () => DB::table('person_relationships')->insert(relationshipRow($ada->id, 'guardian')));
    } catch (UniqueConstraintViolationException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->toBeInstanceOf(UniqueConstraintViolationException::class)
        ->and($refusal?->getMessage())->toContain(PERSON_TYPE_UNIQUE)
        ->and(DB::table('person_relationships')->where('person_id', $ada->id->value)->count())->toBe(1);
});

it('allows the same Person another type, and another Person the same type: the pair is what is unique', function () {
    $ada = Identity::savedPerson('Ada');
    $grace = Identity::savedPerson('Grace');

    DB::table('person_relationships')->insert(relationshipRow($ada->id, 'guardian'));
    DB::table('person_relationships')->insert(relationshipRow($ada->id, 'volunteer'));
    DB::table('person_relationships')->insert(relationshipRow($grace->id, 'guardian'));

    expect(DB::table('person_relationships')->count())->toBe(3);
});
