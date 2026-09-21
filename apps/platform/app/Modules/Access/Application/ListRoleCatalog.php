<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Shared\Domain\Actor;

/**
 * The system roles, for the administration screens that offer them. Access owns the catalog; the Console renders
 * this and holds no role of its own. Needs `identity.accounts.view`: it is metadata about roles an operator who may
 * see accounts already sees on them, and it grants nothing.
 */
final readonly class ListRoleCatalog
{
    public function __construct(private AuthorizeAction $authorize) {}

    /**
     * @return list<RoleDescriptor>
     *
     * @throws AccessDenied
     */
    public function __invoke(Actor $actor): array
    {
        ($this->authorize)($actor, Capability::ViewAccounts);

        return array_map(RoleDescriptor::of(...), Role::cases());
    }
}
