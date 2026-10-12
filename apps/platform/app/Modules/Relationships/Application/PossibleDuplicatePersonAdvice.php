<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use RuntimeException;

/**
 * The new Person looks like someone already known (ADR 0038, P7). Advice, never a merge: nothing was created, and the
 * caller may record the Person again saying they are distinct. Each candidate is an id, a display name and what matched
 * (`email` and/or `display_name`), exactly as CRM's own registration returns them.
 */
final class PossibleDuplicatePersonAdvice extends RuntimeException
{
    /** @param  list<PersonAdvice>  $candidates */
    public function __construct(public readonly array $candidates)
    {
        parent::__construct('This looks like someone who is already in the directory.');
    }
}
