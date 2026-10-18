<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The database the historical-upgrade tests own (tests/Upgrade; G6 WP3b), and the only way they reach it.
 *
 * Why it is separate. The tests build the schema production ran at v0.3.0 (15 migrations), load literal v0.3.0 rows into it and then run
 * the other 14 migrations. That cannot happen inside RefreshDatabase's transaction (MariaDB commits DDL), and it cannot happen in
 * `flowlife_test`, which the rest of the suite keeps fully migrated. So it has a database no other suite touches, created beside
 * `flowlife_test` by `./flow` (scripts/lib/common.sh: ensure_mariadb_test_db, ensure_postgres_test_db), on the same server.
 *
 * What it will and will not do. Every statement that creates or destroys anything goes through a NAMED connection, `upgrade`, built
 * here and nowhere else, and `connect()` re-checks, on every call, that it points at exactly `flowlife_upgrade_test`:
 *
 * - the process is the testing environment, and its inherited default connection is a MariaDB or PostgreSQL `_test` database (the
 *   same rule tests/TestCase.php applies), so a shell with a development or production `.env` is refused;
 * - the host is one the Compose stack or a developer's own machine uses, never a remote name;
 * - the database name is the constant below (tests/Upgrade/UpgradeDatabaseGuardTest pins its value), not read from configuration, and the connection's URL and socket settings are blanked,
 *   because a `DB_URL` or `DB_SOCKET` inherited from the environment could otherwise name another database or server;
 * - the SERVER, asked after connecting, reports the same database name.
 *
 * Nothing here ever runs a statement on the default connection, except `useAsDefault()`, which a test calls to let the application's
 * own code (the Authorizer, the Artisan commands) read the upgraded schema; `restoreDefault()` puts it back.
 */
final class UpgradeDatabase
{
    public const string NAME = 'flowlife_upgrade_test';

    public const string CONNECTION = 'upgrade';

    /** Hosts the dev stack and CI use. Anything else is a connection this class will not run destructive statements on. */
    private const array HOSTS = ['mariadb', 'postgres', 'localhost', '127.0.0.1', '::1'];

    /**
     * The 15 migrations v0.3.0 shipped, the whole of production's schema, oldest first. tests/Architecture/DeployedMigrationsTest.php pins
     * their bytes against the release tag; this list names the same files, and `historicalPaths()` refuses to run unless they are
     * the first 15 migrations in the directory, so a later migration can never be mistaken for one of them.
     */
    public const array HISTORICAL = [
        '0001_01_01_000001_create_cache_table.php',
        '0001_01_01_000002_create_jobs_table.php',
        '2026_09_19_000001_create_people_table.php',
        '2026_09_19_000002_create_accounts_table.php',
        '2026_09_19_000003_create_account_invitations_table.php',
        '2026_09_20_000001_create_security_events_table.php',
        '2026_09_20_000002_create_sessions_table.php',
        '2026_09_20_000003_create_role_assignments_table.php',
        '2026_09_20_000004_add_role_key_index_to_role_assignments.php',
        '2026_09_20_000005_create_password_reset_tokens_table.php',
        '2026_09_21_000001_create_account_totp_factors_table.php',
        '2026_09_21_000002_create_account_recovery_codes_table.php',
        '2026_09_22_000001_add_channel_to_account_invitations.php',
        '2026_09_23_000001_add_security_generation_to_accounts.php',
        '2026_09_23_000002_create_membership_grants_table.php',
    ];

    private static ?string $inheritedDefault = null;

    /** The connection `connect()` last verified, kept so `cleanVerified()` can run in afterAll, when the application is gone. */
    private static ?Connection $verified = null;

    /** The upgrade database, validated afresh. Throws rather than return a connection that is not exactly the intended one. */
    public static function connect(): Connection
    {
        $default = self::$inheritedDefault ?? config()->string('database.default');
        if ($default === self::CONNECTION) {
            // useAsDefault() is in force: the inherited connection is the one remembered from before it.
            throw new RuntimeException('The upgrade connection is already the default; the inherited connection was not remembered.');
        }

        $inherited = config()->array("database.connections.{$default}");
        $driver = $inherited['driver'] ?? null;
        $host = $inherited['host'] ?? null;
        $inheritedName = $inherited['database'] ?? null;

        if (! app()->environment('testing')) {
            throw new RuntimeException('Refusing to touch the upgrade database outside the testing environment.');
        }
        if (! in_array($driver, ['mariadb', 'pgsql'], true)) {
            throw new RuntimeException('The upgrade tests run on MariaDB or PostgreSQL only.');
        }
        if (! is_string($inheritedName) || ! str_ends_with($inheritedName, '_test') || $inheritedName === self::NAME) {
            throw new RuntimeException('Refusing to derive the upgrade connection from ['.(is_string($inheritedName) ? $inheritedName : 'unknown').']: the test run itself must be on its own "_test" database.');
        }
        if (! is_string($host) || ! in_array($host, self::HOSTS, true)) {
            throw new RuntimeException('Refusing to run destructive upgrade setup against host ['.(is_string($host) ? $host : 'unknown').']: only '.implode(', ', self::HOSTS).' are accepted.');
        }
        config(['database.connections.'.self::CONNECTION => [...$inherited, 'database' => self::NAME, 'url' => null, 'unix_socket' => '']]);
        DB::purge(self::CONNECTION);
        $connection = DB::connection(self::CONNECTION);

        $reported = $connection->scalar($driver === 'pgsql' ? 'select current_database()' : 'select database()');
        if ($reported !== self::NAME) {
            DB::purge(self::CONNECTION);
            throw new RuntimeException('The server reports database ['.(is_string($reported) ? $reported : 'none').'], not '.self::NAME.'.');
        }

        return self::$verified = $connection;
    }

    /**
     * Drops every table in the connection `connect()` last verified. For a file's afterAll, where the application is already gone and
     * `connect()` cannot be called again: it acts only on the object that passed those checks.
     */
    public static function cleanVerified(): void
    {
        if (self::$verified !== null) {
            self::$verified->getSchemaBuilder()->dropAllTables();
        }
    }

    /** Drops every table in the upgrade database, and nothing anywhere else. */
    public static function clean(): void
    {
        self::connect();
        Schema::connection(self::CONNECTION)->dropAllTables();
    }

    /**
     * Makes the upgrade database the application's default connection, so its own code reads it. Call `restoreDefault()` afterwards.
     * Refuses unless the connection checks out, so a failed `connect()` can never leave the default pointing somewhere unverified.
     */
    public static function useAsDefault(): void
    {
        self::connect();
        self::$inheritedDefault ??= config()->string('database.default');
        config(['database.default' => self::CONNECTION]);
        DB::setDefaultConnection(self::CONNECTION);
    }

    public static function restoreDefault(): void
    {
        if (self::$inheritedDefault !== null) {
            config(['database.default' => self::$inheritedDefault]);
            DB::setDefaultConnection(self::$inheritedDefault);
            self::$inheritedDefault = null;
        }
    }

    /** @return list<string> absolute paths of the 15 v0.3.0 migrations */
    public static function historicalPaths(): array
    {
        $files = self::migrationFiles();
        if (array_slice($files, 0, count(self::HISTORICAL)) !== self::HISTORICAL) {
            throw new RuntimeException('The first '.count(self::HISTORICAL).' migrations are not the v0.3.0 ones. A migration must never be dated before a deployed one.');
        }

        return array_map(static fn (string $name): string => database_path('migrations/'.$name), self::HISTORICAL);
    }

    /** @return list<string> the migrations after v0.3.0, in the order they run */
    public static function pendingNames(): array
    {
        return array_slice(self::migrationFiles(), count(self::HISTORICAL));
    }

    /** @return list<string> every migration file name, in the order the Migrator runs them */
    private static function migrationFiles(): array
    {
        $names = array_map(basename(...), glob(database_path('migrations/*_*.php')) ?: []);
        usort($names, strcmp(...));

        return $names;
    }

    /** Runs exactly the 15 v0.3.0 migrations, with the real migration runner, on the upgrade database. */
    public static function migrateHistorical(): void
    {
        self::connect();
        $status = Artisan::call('migrate', [
            '--database' => self::CONNECTION, '--path' => self::historicalPaths(), '--realpath' => true, '--force' => true, '--no-interaction' => true,
        ]);
        if ($status !== 0) {
            throw new RuntimeException('Building the v0.3.0 schema failed: '.Artisan::output());
        }
    }

    /** Runs every migration that has not run, with the real migration runner, on the upgrade database. Throws what a migration throws. */
    public static function migratePending(): void
    {
        self::connect();
        $status = Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true, '--no-interaction' => true]);
        if ($status !== 0) {
            throw new RuntimeException('Upgrading failed: '.Artisan::output());
        }
    }

    /** @return list<string> the migrations recorded as run, in the order they ran */
    public static function ran(): array
    {
        /** @var list<string> */
        return DB::connection(self::CONNECTION)->table('migrations')->orderBy('id')->pluck('migration')->map(static fn (mixed $name): string => is_string($name) ? $name.'.php' : '')->all();
    }

    /** @return list<int> the batch of each recorded migration, in the order they ran */
    public static function batches(): array
    {
        return array_values(array_map(
            static fn (mixed $batch): int => is_int($batch) || is_string($batch) ? (int) $batch : -1,
            DB::connection(self::CONNECTION)->table('migrations')->orderBy('id')->pluck('batch')->all(),
        ));
    }

    /**
     * @return list<string> every table in the upgrade database, sorted. Asked for by schema: without one, MariaDB lists the tables of EVERY
     *                      database the user can see, `flowlife_test`'s included.
     */
    public static function tables(): array
    {
        $schema = Schema::connection(self::CONNECTION);
        $names = array_map(
            static fn (array $table): string => $table['name'],
            $schema->getTables(DB::connection(self::CONNECTION)->getDriverName() === 'pgsql' ? 'public' : self::NAME),
        );
        sort($names, SORT_STRING);

        return $names;
    }
}
