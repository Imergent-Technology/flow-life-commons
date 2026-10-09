<?php

declare(strict_types=1);

namespace App\Modules\Access\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Renames the persisted `guardian` role key to `guardian-full` (ADR 0038, M6).
 *
 * The release that runs this is `restore-required` (ADR 0027). A code-only rollback would
 * leave the previous application reading `guardian-full` as an unknown key, which grants
 * nothing and locks every former Guardian-role user out. Production recovery is the
 * pre-migration backup, not `down()`. `down()` exists so a development database can be
 * rolled back; it is not the release's rollback.
 *
 * The update preserves every assignment. It creates no row, writes no security event, and
 * creates no relationship. It refuses to run when a `guardian-full` row already exists,
 * because the unique key on (person, role) would make the rename collide or, if it did
 * not, would make the result ambiguous.
 */
final class RenameGuardianRoleAssignments
{
    public function up(ConnectionInterface $database): void
    {
        $database->transaction(function () use ($database): void {
            if ($this->idsWithExactKey($database, 'guardian-full') !== []) {
                throw new RuntimeException('Refusing to rename role key guardian to guardian-full: a guardian-full assignment already exists.');
            }

            foreach ($this->idsWithExactKey($database, 'guardian') as $id) {
                $database->table('role_assignments')->where('id', $id)->update(['role_key' => 'guardian-full']);
            }
        });
    }

    public function down(ConnectionInterface $database): void
    {
        $database->transaction(function () use ($database): void {
            if ($this->idsWithExactKey($database, 'guardian') !== []) {
                throw new RuntimeException('Refusing to rename role key guardian-full back to guardian: a guardian assignment already exists.');
            }

            foreach ($this->idsWithExactKey($database, 'guardian-full') as $id) {
                $database->table('role_assignments')->where('id', $id)->update(['role_key' => 'guardian']);
            }
        });
    }

    /**
     * Compared in PHP, not only in SQL. MariaDB matches VARCHAR case-insensitively and
     * PostgreSQL does not; a wrongly-cased key is not this role on either engine.
     *
     * @return list<string>
     */
    private function idsWithExactKey(ConnectionInterface $database, string $key): array
    {
        $ids = [];
        foreach ($database->table('role_assignments')->where('role_key', $key)->orderBy('id')->get(['id', 'role_key']) as $row) {
            assert(is_string($row->id) && is_string($row->role_key));
            if ($row->role_key === $key) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }
}
