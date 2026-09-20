<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Shared\Domain\PersonId;

interface PersonRepository
{
    /** Insert or update. */
    public function save(Person $person): void;

    public function find(PersonId $id): ?Person;
}
