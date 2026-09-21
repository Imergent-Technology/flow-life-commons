<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Identity\Application\AccountDirectory;
use App\Modules\Identity\Application\AccountNotFound;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;

/**
 * An operator removes a system role from the Person behind an Account. Needs `access.roles.assign`. RevokeRole does
 * the rest, unchanged, including the last-administrator protection (`LastAdministratorRequired`).
 */
final readonly class RevokeRoleFromAccount
{
    public function __construct(
        private AuthorizeAction $authorize,
        private AccountDirectory $directory,
        private RevokeRole $revoke,
        private AccountViews $views,
    ) {}

    /**
     * @throws AccessDenied
     * @throws AccountNotFound
     * @throws UnknownRole
     * @throws LastAdministratorRequired
     */
    public function __invoke(Actor $actor, AccountId $account, string $roleKey): AccountView
    {
        ($this->authorize)($actor, Capability::AssignRoles);

        $role = Role::tryFrom($roleKey) ?? throw new UnknownRole;
        $person = ($this->directory->find($account) ?? throw new AccountNotFound)->personId;
        ($this->revoke)($actor, $person, $role);

        return $this->views->of($account);
    }
}
