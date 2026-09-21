<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountDirectory;
use App\Modules\Identity\Application\AccountNotFound;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/**
 * An operator grants a system role to the Person behind an Account. Needs `access.roles.assign`. It only finds the
 * Person and names the catalog role: GrantRole does the rest, unchanged (authorization, idempotency, the audit event).
 * The client names a KEY; it can never name a capability, and an unknown key is refused.
 */
final readonly class GrantRoleToAccount
{
    public function __construct(
        private AuthorizeAction $authorize,
        private AccountDirectory $directory,
        private GrantRole $grant,
        private AccountViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws AccountNotFound
     * @throws UnknownRole
     */
    public function __invoke(Actor $actor, AccountId $account, string $roleKey): AccountView
    {
        ($this->authorize)($actor, Capability::AssignRoles);

        $role = Role::tryFrom($roleKey) ?? throw new UnknownRole;
        $person = ($this->directory->find($account) ?? throw new AccountNotFound)->personId;
        ($this->grant)($actor, $person, $role);

        return $this->views->of($account);
    }
}
