<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\PersonId;

interface PersonRepository
{
    /** Insert or update. */
    public function save(Person $person): void;

    public function find(PersonId $id): ?Person;

    /**
     * Every Person among these ids that exists, in one query. An id with no matching Person is
     * simply absent from the result, not an error.
     *
     * @param  list<PersonId>  $ids
     * @return list<Person>
     */
    public function findMany(array $ids): array;
}
