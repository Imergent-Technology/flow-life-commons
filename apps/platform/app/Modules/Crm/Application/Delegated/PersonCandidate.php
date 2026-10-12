<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use App\Shared\Domain\PersonId;

/** A Person a lookup or a duplicate check found: an id, a display name and what matched. No contact value (ADR 0038, P4). */
final readonly class PersonCandidate
{
    /** @param  string  $matchedOn  `email`, `phone` or `display_name` */
    public function __construct(public PersonId $id, public string $displayName, public string $matchedOn) {}
}
