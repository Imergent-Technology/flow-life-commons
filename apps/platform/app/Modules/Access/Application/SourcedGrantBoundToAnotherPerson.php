<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use DomainException;

/**
 * An idempotent retry named a Person other than the one this source's grant already belongs to.
 * The existing row is left as it is: a retry must not reassign the source.
 */
final class SourcedGrantBoundToAnotherPerson extends DomainException
{
    public function __construct()
    {
        parent::__construct('This source already grants the role to a different person.');
    }
}
