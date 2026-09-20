<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach a real remote service (the breached-password range API included): a
        // test that needs the network answers it with Http::fake().
        Http::preventStrayRequests();

        // Integration tests run on the real engines only (ADR 0014): no SQLite
        // "stand-in", which would hide MariaDB/PostgreSQL differences.
        $connection = config()->string('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if (! in_array($driver, ['mariadb', 'pgsql'], true)) {
            throw new RuntimeException('Tests must run on MariaDB or PostgreSQL, not ['.(is_string($driver) ? $driver : 'unknown').'].');
        }

        // Feature tests migrate:fresh the configured database. Never allow that
        // to be the development database.
        $database = config("database.connections.{$connection}.database");

        if (! is_string($database) || ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                'Refusing to run tests against database ['.(is_string($database) ? $database : 'unknown').']: '
                .'the test database name must end in "_test".',
            );
        }
    }
}
