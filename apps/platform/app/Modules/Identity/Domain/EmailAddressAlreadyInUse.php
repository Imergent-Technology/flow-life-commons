<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use DomainException;
use Throwable;

/** Another Account already holds this canonical email address (case variants included). */
final class EmailAddressAlreadyInUse extends DomainException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('An account already uses this email address.', 0, $previous);
    }
}
