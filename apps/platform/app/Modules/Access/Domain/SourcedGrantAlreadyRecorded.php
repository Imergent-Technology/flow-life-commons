<?php

declare(strict_types=1);

namespace App\Modules\Access\Domain;

use DomainException;
use Throwable;

/** This source already records this role. The unique key is (source type, source id, role), not the Person. */
final class SourcedGrantAlreadyRecorded extends DomainException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('This source already records this role.', 0, $previous);
    }
}
