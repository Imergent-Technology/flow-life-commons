<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Identity\Application\AccountDirectory;
use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\ManagedAccount;
use App\Shared\Domain\AccountId;

/**
 * Builds what the administration surface presents: Identity's read model joined with Access's assignments, read
 * fresh and in one query for a whole page. It decides nothing and authorizes nothing; the use case that returns a
 * view has already authorized. A stored role key the catalog does not know grants nothing (ADR 0017) and is not shown.
 */
final readonly class AccountViews
{
    public function __construct(
        private AccountDirectory $directory,
        private RoleAssignmentRepository $assignments,
    ) {}

    /**
     * @throws AccountNotFound
     */
    public function of(AccountId $id): AccountView
    {
        $account = $this->directory->find($id) ?? throw new AccountNotFound;

        return $this->many([$account])[0];
    }

    /**
     * @param  list<ManagedAccount>  $accounts
     * @return list<AccountView>
     */
    public function many(array $accounts): array
    {
        $held = $this->assignments->forPeople(array_map(static fn (ManagedAccount $a) => $a->personId, $accounts));

        $views = [];
        foreach ($accounts as $account) {
            $assignments = [];
            foreach ($held[$account->personId->value] ?? [] as $assignment) {
                $role = Role::tryFrom($assignment->roleKey);
                if ($role !== null) {
                    $assignments[] = new RoleAssignmentView(RoleDescriptor::of($role), $assignment->grantedAt);
                }
            }
            $views[] = new AccountView($account, $assignments);
        }

        return $views;
    }
}
