<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

/** At most ten candidates, and whether there were more (ADR 0038, P4). */
final readonly class PersonCandidates
{
    /** @param  list<PersonCandidate>  $candidates */
    public function __construct(public array $candidates, public bool $truncated) {}
}
