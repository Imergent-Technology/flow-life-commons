<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\PersonId;

/** Whether a Person exists. Other modules ask this instead of reading Identity's tables. */
final readonly class PersonExists
{
    public function __construct(private PersonRepository $people) {}

    public function __invoke(PersonId $personId): bool
    {
        return $this->people->find($personId) !== null;
    }
}
