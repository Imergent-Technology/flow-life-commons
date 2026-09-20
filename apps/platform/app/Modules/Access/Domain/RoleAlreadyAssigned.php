<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain;

use DomainException;
use Throwable;

/** The Person already holds this role: an assignment is unique per (person, role). */
final class RoleAlreadyAssigned extends DomainException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The person already holds this role.', 0, $previous);
    }
}
