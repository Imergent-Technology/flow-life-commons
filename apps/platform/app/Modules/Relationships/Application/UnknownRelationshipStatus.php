<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/** A directory filter named a status the type does not have. */
final class UnknownRelationshipStatus extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That status is not part of this relationship.');
    }
}
