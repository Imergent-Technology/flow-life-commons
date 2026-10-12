<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use App\Shared\Domain\PersonId;

/** A Person who might be the one being registered, and why: `email` and/or `display_name`. Directory information only. */
final readonly class DuplicatePersonAdvice
{
    /** @param  list<string>  $matchedOn */
    public function __construct(public PersonId $id, public string $displayName, public array $matchedOn) {}
}
