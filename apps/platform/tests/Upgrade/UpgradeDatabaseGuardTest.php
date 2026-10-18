<?php

declare(strict_types=1);

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
