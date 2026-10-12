<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use App\Shared\Domain\PersonId;

/**
 * The three basic details of a Person, exactly as stored (ADR 0038, P1, P2): the display name, the value of the primary
 * email and the value of the primary phone. Nothing else about the Person or their contact data is in it.
 */
final readonly class PersonBasics
{
    public function __construct(
        public PersonId $id,
        public string $displayName,
        public ?string $primaryEmail,
        public ?string $primaryPhone,
    ) {}
}
