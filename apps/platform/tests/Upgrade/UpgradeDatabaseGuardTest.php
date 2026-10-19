<?php

declare(strict_types=1);

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\UpgradeDatabase as Upgrade;

/*
 * G6 WP3b. The upgrade tests drop every table in their database, so the one door to it (Tests\Support\UpgradeDatabase) must stay shut to
 * anything but exactly `flowlife_upgrade_test`. Each case damages the configuration it inherits in one way a careless environment could,
 * and the door must refuse BEFORE a connection to the upgrade database exists, and before anything is dropped.
 */

function inheritedConnectionName(): string
{
    return config()->string('database.default');
}

beforeAll(function () {
    gc_collect_cycles(); // the earlier suites' connections (see Upgrade::release)
});

afterEach(function () {
    Upgrade::release();
});

it('connects to exactly flowlife_upgrade_test, and the server agrees', function () {
    $connection = Upgrade::connect();
    $driver = $connection->getDriverName();

    expect($connection->scalar($driver === 'pgsql' ? 'select current_database()' : 'select database()'))->toBe('flowlife_upgrade_test')
        ->and(Upgrade::NAME)->toBe('flowlife_upgrade_test')
        ->and(DB::connection()->scalar($driver === 'pgsql' ? 'select current_database()' : 'select database()'))->toBe('flowlife_test');
});

it('refuses to be built from a connection that is not a _test database, or is the upgrade database itself', function (string $database) {
    config(['database.connections.'.inheritedConnectionName().'.database' => $database]);

    expect(Upgrade::connect(...))->toThrow(RuntimeException::class, 'the test run itself must be on its own "_test" database')
        ->and(array_key_exists(Upgrade::CONNECTION, DB::getConnections()))->toBeFalse();
})->with(['the development database' => 'flowlife', 'a production-looking name' => 'flowlife_production', 'a test name with a suffix after it' => 'flowlife_test_backup', 'the upgrade database' => 'flowlife_upgrade_test']);

it('refuses a host that is not the dev stack or this machine', function (string $host) {
    config(['database.connections.'.inheritedConnectionName().'.host' => $host]);

    expect(Upgrade::connect(...))->toThrow(RuntimeException::class, 'only mariadb, postgres, localhost, 127.0.0.1, ::1 are accepted')
        ->and(array_key_exists(Upgrade::CONNECTION, DB::getConnections()))->toBeFalse();
})->with(['a remote name' => 'db.example.com', 'a public address' => '203.0.113.5', 'a lookalike' => 'mariadb.example.com']);

it('refuses outside the testing environment', function () {
    app()->detectEnvironment(static fn (): string => 'production');

    expect(Upgrade::connect(...))->toThrow(RuntimeException::class, 'outside the testing environment')
        ->and(Upgrade::clean(...))->toThrow(RuntimeException::class, 'outside the testing environment')
        ->and(array_key_exists(Upgrade::CONNECTION, DB::getConnections()))->toBeFalse();
});

it('is not redirected by a URL or a socket inherited from the environment', function () {
    $name = inheritedConnectionName();
    $driver = config()->string("database.connections.{$name}.driver");
    $other = $driver === 'pgsql' ? 'pgsql://x:y@postgres:5432/flowlife' : 'mysql://x:y@mariadb:3306/flowlife';
    config(["database.connections.{$name}.url" => $other, "database.connections.{$name}.unix_socket" => '/nonexistent/mysql.sock']);

    $connection = Upgrade::connect();

    expect($connection->scalar($driver === 'pgsql' ? 'select current_database()' : 'select database()'))->toBe('flowlife_upgrade_test');
});

it('leaves the default connection where it was when it refuses to make the upgrade database the default', function () {
    $before = config('database.default');
    config(['database.connections.'.inheritedConnectionName().'.host' => 'db.example.com']);

    expect(Upgrade::useAsDefault(...))->toThrow(RuntimeException::class)
        ->and(config('database.default'))->toBe($before)
        ->and(DB::getDefaultConnection())->toBe($before);
});

it('puts the default connection back after use', function () {
    $before = config('database.default');

    Upgrade::useAsDefault();
    expect(config('database.default'))->toBe(Upgrade::CONNECTION);

    Upgrade::restoreDefault();
    expect(config('database.default'))->toBe($before)
        ->and(DB::getDefaultConnection())->toBe($before);
});

it('refuses any read, write or sticky routing in the inherited connection, however empty, before a connection exists', function (string $key, mixed $value) {
    $name = inheritedConnectionName();
    // The case that was demonstrated: the top-level host and database pass every check, and the write side points elsewhere.
    config(["database.connections.{$name}.{$key}" => $value]);

    expect(Upgrade::connect(...))->toThrow(RuntimeException::class, "Refusing a connection with a [{$key}] setting")
        ->and(Upgrade::clean(...))->toThrow(RuntimeException::class, "Refusing a connection with a [{$key}] setting")
        ->and(Upgrade::useAsDefault(...))->toThrow(RuntimeException::class, "Refusing a connection with a [{$key}] setting")
        ->and(array_key_exists(Upgrade::CONNECTION, DB::getConnections()))->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe($name);
})->with(function () {
    foreach (['read', 'write', 'direct'] as $key) {
        yield "{$key} naming another database" => [$key, ['database' => 'flowlife']];
        yield "{$key} empty" => [$key, []];
        yield "{$key} null" => [$key, null];
    }
    yield 'pooled true' => ['pooled', true];
    yield 'pooled false' => ['pooled', false];
    yield 'pooled null' => ['pooled', null];
    yield 'sticky true' => ['sticky', true];
    yield 'sticky false' => ['sticky', false];
    yield 'sticky null' => ['sticky', null];
});

it('asks the WRITE side which database it is on, and refuses a write side that is somewhere else', function () {
    // Built without Upgrade::connect(), because connect() now refuses this shape: a connection whose top-level database is the upgrade
    // database and whose write side is the test database. A read-side question (what the check used to ask) is answered with the upgrade
    // database; the write side, which would take the DDL, is on flowlife_test. Nothing is run on either but a SELECT.
    $name = inheritedConnectionName();
    $inherited = config()->array("database.connections.{$name}");
    $split = (new ConnectionFactory(app()))->make([...$inherited, 'database' => Upgrade::NAME, 'url' => null, 'unix_socket' => '', 'read' => ['database' => Upgrade::NAME], 'write' => ['database' => $inherited['database']]], 'split');
    $driver = $split->getDriverName();
    $sql = $driver === 'pgsql' ? 'select current_database()' : 'select database()';

    try {
        expect($split->scalar($sql))->toBe('flowlife_upgrade_test');
        expect(fn () => Upgrade::assertServerDatabase($split))->toThrow(RuntimeException::class, 'The server reports database [flowlife_test], not flowlife_upgrade_test.');
    } finally {
        $split->disconnect();
    }
});

it('accepts the upgrade connection and refuses any other database the server reports', function () {
    Upgrade::assertServerDatabase(Upgrade::connect());

    expect(fn () => Upgrade::assertServerDatabase(DB::connection()))->toThrow(RuntimeException::class, 'The server reports database [flowlife_test], not flowlife_upgrade_test.');
});

it('refuses to clean from settings that name routing or another database, before it connects', function (array $tamper, string $message) {
    Upgrade::connect();
    $property = new ReflectionProperty(Upgrade::class, 'verified');
    $verified = $property->getValue();
    assert(is_array($verified));

    try {
        $property->setValue(null, [...$verified, ...$tamper]);

        expect(Upgrade::cleanVerified(...))->toThrow(RuntimeException::class, $message);
    } finally {
        $property->setValue(null, $verified);
    }
})->with([
    'a write side' => [['write' => ['database' => 'flowlife_test']], 'Refusing a connection with a [write] setting'],
    'a read side' => [['read' => []], 'Refusing a connection with a [read] setting'],
    'another database' => [['database' => 'flowlife_test'], 'Refusing to clean database [flowlife_test]'],
]);

/** @return list<string> the tables in the test run's own database */
function testDatabaseTables(): array
{
    $connection = DB::connection();
    $names = array_map(
        static fn (array $table): string => $table['name'],
        $connection->getSchemaBuilder()->getTables($connection->getDriverName() === 'pgsql' ? 'public' : $connection->getDatabaseName()),
    );
    sort($names, SORT_STRING);

    return $names;
}

it('refuses the PostgreSQL direct endpoint that sent migrations to another database, before any migration runs', function () {
    // The demonstrated bypass (Laravel 13.32): `direct` naming flowlife_test. connect()'s own question was answered by the upgrade database,
    // and the migrator then resolved `upgrade::direct` and created all 15 historical tables in flowlife_test. Refused here by the key, so
    // nothing is created anywhere. On MariaDB the key means nothing to the driver and is refused all the same.
    $name = inheritedConnectionName();
    config(["database.connections.{$name}.direct" => ['database' => 'flowlife_test']]);
    $before = testDatabaseTables();

    expect(Upgrade::migrateHistorical(...))->toThrow(RuntimeException::class, 'Refusing a connection with a [direct] setting')
        ->and(Upgrade::migratePending(...))->toThrow(RuntimeException::class, 'Refusing a connection with a [direct] setting')
        ->and(Upgrade::clean(...))->toThrow(RuntimeException::class, 'Refusing a connection with a [direct] setting')
        ->and(array_key_exists(Upgrade::CONNECTION, DB::getConnections()))->toBeFalse()
        ->and(array_key_exists(Upgrade::CONNECTION.'::direct', DB::getConnections()))->toBeFalse()
        ->and(testDatabaseTables())->toBe($before);
});

it('refuses, from connect() itself, a connection the server puts on another database, and marks nothing verified', function () {
    // The mismatch comes from inherited configuration that runs at connect time, not from the database name (which connect() fixes):
    // PostgreSQL's `connect_via_database` is the DSN's dbname, and on MariaDB an unvalidated `isolation_level` is executed as SQL
    // ("SET SESSION TRANSACTION ISOLATION LEVEL %s;"), so it can carry a USE. Only SELECTs run on the connection that results. If the call
    // to assertServerDatabase() were removed from connect(), this connection would be accepted.
    $name = inheritedConnectionName();
    $driver = config()->string("database.connections.{$name}.driver");
    config(match ($driver) {
        'pgsql' => ["database.connections.{$name}.connect_via_database" => 'flowlife_test'],
        'mariadb' => ["database.connections.{$name}.isolation_level" => 'READ COMMITTED; USE flowlife_test'],
        default => throw new LogicException("No mismatch recipe for {$driver}."),
    });
    $property = new ReflectionProperty(Upgrade::class, 'verified');
    $verifiedBefore = $property->getValue();

    expect(Upgrade::connect(...))->toThrow(RuntimeException::class, 'The server reports database [flowlife_test], not flowlife_upgrade_test.')
        ->and(array_key_exists(Upgrade::CONNECTION, DB::getConnections()))->toBeFalse()
        ->and($property->getValue())->toBe($verifiedBefore)
        ->and(Upgrade::useAsDefault(...))->toThrow(RuntimeException::class, 'The server reports database [flowlife_test]')
        ->and(DB::getDefaultConnection())->toBe($name);
});
