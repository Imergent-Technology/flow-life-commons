<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Shared\Domain\PersonId;

/**
 * A Person as management shows them: the id and the CURRENT display name, and nothing else. `displayName` is null only when
 * Identity no longer holds the Person. Never an Account, a login email, a role or any security state.
 */
final readonly class ResourcePerson
{
    public function __construct(public PersonId $id, public ?string $displayName) {}
}
