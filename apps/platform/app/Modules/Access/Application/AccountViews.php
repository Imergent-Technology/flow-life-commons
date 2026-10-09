<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Access\Domain\SourcedRoleGrantRepository;
use App\Modules\Identity\Application\AccountDirectory;
use App\Modules\Identity\Application\AccountNotFound;
use App\Modules\Identity\Application\ManagedAccount;
use App\Shared\Domain\AccountId;

/**
 * Builds what the administration surface presents: Identity's read model joined with Access's assignments and
 * sourced grants, read fresh and in one query each for a whole page. It decides nothing and authorizes nothing; the
 * use case that returns a view has already authorized. A stored role key the catalog does not know grants nothing
 * (ADR 0017) and is not shown. Sourced grants are listed apart from independent assignments and carry no revoke action.
 */
final readonly class AccountViews
{
    public function __construct(
        private AccountDirectory $directory,
        private RoleAssignmentRepository $assignments,
        private SourcedRoleGrantRepository $sourcedGrants,
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
        $people = array_map(static fn (ManagedAccount $account) => $account->personId, $accounts);
        $held = $this->assignments->forPeople($people);
        $sourced = $this->sourcedGrants->forPeople($people);

        $views = [];
        foreach ($accounts as $account) {
            $assignments = [];
            foreach ($held[$account->personId->value] ?? [] as $assignment) {
                $role = Role::tryFrom($assignment->roleKey);
                if ($role !== null) {
                    $assignments[] = new RoleAssignmentView(RoleDescriptor::of($role), $assignment->grantedAt);
                }
            }
            $grants = [];
            foreach ($sourced[$account->personId->value] ?? [] as $grant) {
                $role = Role::tryFrom($grant->roleKey);
                $source = RoleGrantSourceType::tryFrom($grant->sourceType);
                if ($role !== null && $source !== null) {
                    $grants[] = new SourcedRoleGrantView(
                        RoleDescriptor::of($role),
                        $source->value,
                        $source->label(),
                        $grant->sourceId,
                        $grant->grantedByAccountId?->value,
                        $grant->grantedAt,
                    );
                }
            }
            $views[] = new AccountView($account, $assignments, $grants);
        }

        return $views;
    }
}
