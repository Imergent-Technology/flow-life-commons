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
    foreach (['read', 'write'] as $key) {
        yield "{$key} naming another database" => [$key, ['database' => 'flowlife']];
        yield "{$key} empty" => [$key, []];
        yield "{$key} null" => [$key, null];
    }
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
