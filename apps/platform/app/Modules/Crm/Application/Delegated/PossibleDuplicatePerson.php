<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use RuntimeException;

/**
 * The Person being registered looks like someone already known (ADR 0038, P7). Advice, never a merge: nothing was created.
 * Candidates carry an id, a display name and what matched, exactly as CRM's own registration returns them.
 */
final class PossibleDuplicatePerson extends RuntimeException
{
    /** @param  list<DuplicatePersonAdvice>  $candidates */
    public function __construct(public readonly array $candidates)
    {
        parent::__construct('This looks like someone who is already in the directory.');
    }
}
