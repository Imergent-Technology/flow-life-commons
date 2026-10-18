<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * ADR 0038, M6. Renames stored `guardian` role assignments to `guardian-full`.
 *
 * The logic lives here, not in application code, so the migration stays what it was when it
 * shipped however Access changes later (G6 WP3a, debt item D8). It depends only on the framework.
 *
 * The release that contains this migration is restore-required (ADR 0027). Rolling back
 * only the application code after the keys have moved fails closed: the previous catalog
 * does not know `guardian-full`, so every former Guardian-role user loses that bundle.
 * Recovery is the pre-migration backup. down() is for a development database only.
 *
 * What it changes, and against which baseline:
 * - The rename keeps every holder on the bundle the same release's catalog gives `guardian-full`,
 *   which is the bundle the development line's `guardian` had. The migration defines no bundle.
 * - Production v0.3.0's `guardian` granted `console.access` only. So measured against production
 *   the release this migration ships in EXPANDS authority: every holder gains nine capabilities,
 *   and that needs the product owner's authorization (docs/runbooks/g10-release-readiness.md).
 * - No row is created, no other key is touched, no security event is written, and no relationship
 *   is created. Historical role.granted events keep the key they recorded.
 *
 * How it must stay, and why:
 * - The explicit transaction is what makes the rename atomic on MariaDB, where Laravel does not
 *   wrap a migration in one (only PostgreSQL's grammar has transactional DDL). Do not remove it.
 * - It uses the default connection, which `migrate --database=` switches; it names none.
 * - Keys are compared in PHP with ===, not in SQL alone. MariaDB matches VARCHAR without regard to
 *   case, trailing spaces or accents and PostgreSQL does not; a variant key is not this role on
 *   either engine. No engine-specific SQL (BINARY, lower()) is used to do that instead.
 * - It refuses when an exact `guardian-full` row already exists, so a second run changes nothing.
 * - Known engine difference (G6 WP3-02, kept on purpose): when one Person holds `guardian` and a
 *   collation-equal variant of `guardian-full` (`Guardian-Full`, `guardian-full ` or
 *   `guardián-full`), MariaDB's unique key rejects the rename (1062) and the transaction leaves
 *   every row as it was, while PostgreSQL renames it. The v0.3.0 catalog could not create such a
 *   key; the release's read-only pre-flight is where to look for one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $database = DB::connection();

        $database->transaction(function () use ($database): void {
            if ($this->idsWithExactKey($database, 'guardian-full') !== []) {
                throw new RuntimeException('Refusing to rename role key guardian to guardian-full: a guardian-full assignment already exists.');
            }

            foreach ($this->idsWithExactKey($database, 'guardian') as $id) {
                $database->table('role_assignments')->where('id', $id)->update(['role_key' => 'guardian-full']);
            }
        });
    }

    public function down(): void
    {
        $database = DB::connection();

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
};
