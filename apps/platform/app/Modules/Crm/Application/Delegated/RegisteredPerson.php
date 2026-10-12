<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use App\Shared\Domain\PersonId;

/** The Person a minimal registration created. */
final readonly class RegisteredPerson
{
    public function __construct(public PersonId $id, public string $displayName) {}
}
