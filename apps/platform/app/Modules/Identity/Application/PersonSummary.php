<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Shared\Domain\PersonId;

/**
 * The minimum another module may know about a Person: an id and a display name. No email, no
 * Account or security state — a module that needs more than this is asking Identity for the
 * wrong thing.
 */
final readonly class PersonSummary
{
    public function __construct(
        public PersonId $id,
        public string $displayName,
    ) {}
}
