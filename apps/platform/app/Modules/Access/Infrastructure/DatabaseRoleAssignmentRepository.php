<?php

declare(strict_types=1);

namespace App\Modules\Access\Infrastructure;

use App\Modules\Access\Domain\RoleAlreadyAssigned;
use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentId;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Query builder rather than an Eloquent model, as in Audit: with no model there is
 * nothing whose save() or delete() could be used to change an assignment outside the
 * paths the module chooses to provide.
 *
 * Reads are never cached: every authorization decision reflects the rows as they are.
 * The role key is returned exactly as stored. Whether it means anything is the
 * catalog's decision, not this class's.
 */
final readonly class DatabaseRoleAssignmentRepository implements RoleAssignmentRepository
{
    // Constraint name from the role_assignments migration; both engines include it in the error.
    private const string UNIQUE = 'role_assignments_person_id_role_key_unique';

    public function __construct(private ConnectionInterface $database) {}

    public function forPerson(PersonId $personId): array
    {
        $rows = $this->database->table('role_assignments')
            ->where('person_id', $personId->value)
            ->orderBy('granted_at')
            ->orderBy('id')
            ->get();

        $assignments = [];
        foreach ($rows as $row) {
            assert(is_string($row->id) && is_string($row->person_id) && is_string($row->role_key) && is_string($row->granted_at));
            assert($row->granted_by_account_id === null || is_string($row->granted_by_account_id));

            $assignments[] = RoleAssignment::reconstitute(
                RoleAssignmentId::fromString($row->id),
                PersonId::fromString($row->person_id),
                $row->role_key,
                $row->granted_by_account_id === null ? null : AccountId::fromString($row->granted_by_account_id),
                new DateTimeImmutable($row->granted_at, new DateTimeZone('UTC')),
            );
        }

        return $assignments;
    }

    public function remove(PersonId $personId, string $roleKey): bool
    {
        // Compared exactly, for the same reason holders are (see holdersOf): on MariaDB the
        // database alone would also match a wrongly-cased row, and that is not this grant.
        // A locking read, ordered by id like every other read of this table that decides something,
        // so nothing inside a role-mutating transaction reads these rows from a stale snapshot.
        $rows = $this->database->table('role_assignments')
            ->where('person_id', $personId->value)->where('role_key', $roleKey)
            ->orderBy('id')->lockForUpdate()->get(['id', 'role_key']);

        $removed = false;
        foreach ($rows as $row) {
            if ($row->role_key === $roleKey) {
                $removed = $this->database->table('role_assignments')->where('id', $row->id)->delete() > 0 || $removed;
            }
        }

        return $removed;
    }

    public function holdersOf(string $roleKey): array
    {
        return $this->holders($roleKey, lock: false);
    }

    public function lockHoldersOf(string $roleKey): array
    {
        return $this->holders($roleKey, lock: true);
    }

    /**
     * @return list<PersonId>
     */
    private function holders(string $roleKey, bool $lock): array
    {
        // Ordered by id so that every transaction takes these locks in the same order.
        $query = $this->database->table('role_assignments')->where('role_key', $roleKey)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $holders = [];
        foreach ($query->get(['person_id', 'role_key']) as $row) {
            assert(is_string($row->person_id) && is_string($row->role_key));
            if ($row->role_key === $roleKey) { // exact, whatever the engine's collation does
                $holders[] = PersonId::fromString($row->person_id);
            }
        }

        return $holders;
    }

    public function add(RoleAssignment $assignment): void
    {
        try {
            $this->database->table('role_assignments')->insert([
                'id' => $assignment->id->value,
                'person_id' => $assignment->personId->value,
                'role_key' => $assignment->roleKey,
                'granted_by_account_id' => $assignment->grantedByAccountId?->value,
                'granted_at' => $assignment->grantedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw str_contains($e->getMessage(), self::UNIQUE) ? new RoleAlreadyAssigned($e) : $e;
        }
    }
}
