<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Shared\Domain\PersonId;

/** One possible duplicate: a Person's id, display name and why they look like the new one. */
final readonly class PersonAdvice
{
    /** @param  list<string>  $matchedOn */
    public function __construct(public PersonId $id, public string $displayName, public array $matchedOn) {}
}
