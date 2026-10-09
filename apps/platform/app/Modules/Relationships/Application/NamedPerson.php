<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Shared\Domain\PersonId;

/** A Person as a relationship response may name them: an id and, when Identity still has them, a display name. */
final readonly class NamedPerson
{
    public function __construct(public PersonId $id, public ?string $displayName) {}
}
