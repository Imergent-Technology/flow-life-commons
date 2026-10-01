<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use RuntimeException;

/**
 * The Person being registered looks like someone already known. This is advice, never a merge: nothing was created, and a
 * caller who has looked at the candidates may register again, saying the Person is distinct.
 */
final class PossibleDuplicate extends RuntimeException
{
    /** @param  list<DuplicateCandidate>  $candidates */
    public function __construct(public readonly array $candidates)
    {
        parent::__construct('This looks like someone who is already in the directory.');
    }
}
