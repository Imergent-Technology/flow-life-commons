<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use DomainException;
use Throwable;

/** A Person has at most one Account (ADR 0015). */
final class PersonAlreadyHasAccount extends DomainException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('This person already has an account.', 0, $previous);
    }
}
