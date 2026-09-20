<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Identity\Application\ActiveAccountQuery;
use App\Shared\Domain\PersonId;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * The single authority for the last-administrator invariant (ADR 0020): normal operations
 * must never leave the platform with no ACTIVE administrator.
 *
 * **Active administrator** = a Person who holds `platform_administrator` AND whose Account can
 * still authenticate (Identity's rule: active, with a credential, not disabled). An assignment
 * row alone proves nothing: an invited, disabled or account-less administrator does not count.
 *
 * **Every operation that can remove administrator authority goes through here**: revoking the
 * role (RevokeRole) and disabling an administrator's Account (Identity's DisableAccount, via
 * the deactivation guard Access registers). There is one implementation and one lock path, so
 * a revoke and a disable racing each other protect the same invariant.
 *
 * ### Why it is safe under concurrency
 *
 * A bare "count administrators, and if more than one then remove" lets two concurrent removals
 * each see the other still standing, and both succeed. Instead, INSIDE the caller's transaction:
 *
 * 1. **Lock first.** Take `SELECT ... FOR UPDATE` on every administrator assignment row, in id
 *    order. Anything else that can remove authority takes the same lock first, so those
 *    operations queue behind one another rather than interleave.
 * 2. **Then read.** A locking read returns the latest COMMITTED state, so what a predecessor
 *    committed while this transaction waited is seen. (A plain read could instead return the
 *    snapshot InnoDB fixed earlier in the transaction, which is exactly the stale view the lock
 *    exists to prevent; hence nothing that decides the outcome is read before the lock.)
 * 3. **Lock the survivors' Accounts too**, again in id order, so a remaining administrator
 *    cannot be disabled while this decision stands.
 * 4. Decide, and let the caller mutate. The locks are released by the caller's commit or
 *    rollback.
 *
 * Portable: plain row locks, no advisory locks, no PHP or cache locks, no server-local state,
 * and the same statements on MariaDB and PostgreSQL. A grant running concurrently may make a
 * removal conservatively refused (it cannot be counted until it commits); it can never make a
 * removal wrongly permitted, because a grant only adds administrators.
 *
 * Callers must decide authorization BEFORE opening their transaction: reading before the lock
 * would fix the snapshot this class works so hard to avoid.
 */
final readonly class AdministratorContinuity
{
    public function __construct(
        private RoleAssignmentRepository $assignments,
        private ActiveAccountQuery $accounts,
        private ConnectionInterface $database,
    ) {}

    /**
     * Refuses unless removing this Person's administrator authority (by revoking the role or
     * disabling their Account) still leaves at least one other active administrator. Returns
     * quietly for a Person who is not an administrator: there is nothing to protect.
     *
     * @throws LastAdministratorRequired
     */
    public function assertMayRemoveAdministratorAuthorityOf(PersonId $person): void
    {
        $this->requireTransaction();

        $holders = $this->assignments->lockHoldersOf(Role::PlatformAdministrator->value); // 1. lock, then read

        if (! $this->contains($holders, $person)) {
            return;
        }

        $others = array_values(array_filter($holders, fn (PersonId $holder): bool => ! $holder->equals($person)));

        if ($this->accounts->lockActivePersonIds($others) === []) { // 3. and lock the survivors
            throw new LastAdministratorRequired;
        }
    }

    /** @param  list<PersonId>  $people */
    private function contains(array $people, PersonId $person): bool
    {
        foreach ($people as $candidate) {
            if ($candidate->equals($person)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Row locks last only as long as the transaction. Outside one they are released at once,
     * which would be a guarantee in name only.
     */
    private function requireTransaction(): void
    {
        if (! $this->database instanceof Connection || $this->database->transactionLevel() < 1) {
            throw new LogicException('The last-administrator check must run inside the transaction that makes the change.');
        }
    }
}
