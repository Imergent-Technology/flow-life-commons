<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Identity\Application\PersonSummary;

/** A Person who might be the one being registered, and why they look like them. Directory information only. */
final readonly class DuplicateCandidate
{
    /** @param  list<string>  $matchedOn  `email` and/or `display_name` */
    public function __construct(public PersonSummary $person, public array $matchedOn) {}
}
