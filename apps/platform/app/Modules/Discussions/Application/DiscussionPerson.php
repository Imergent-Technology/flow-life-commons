<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Application;

use App\Shared\Domain\PersonId;

/**
 * A Person as a discussion shows them: the id and the CURRENT display name, and nothing else. `displayName` is null only
 * when Identity no longer holds the Person: the words stay, the author is unknown, and nobody can then be their author.
 * Never an Account, a login email, a role or any security state.
 */
final readonly class DiscussionPerson
{
    public function __construct(public PersonId $id, public ?string $displayName) {}
}
