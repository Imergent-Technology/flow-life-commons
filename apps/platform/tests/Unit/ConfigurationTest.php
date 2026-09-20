<?php

declare(strict_types=1);

it('stores and computes timestamps in UTC', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('database.connections.mariadb.timezone'))->toBe('+00:00')
        ->and(config('database.connections.pgsql.timezone'))->toBe('UTC');
});

it('runs on a supported database engine', function () {
    // MariaDB is the canonical engine; PostgreSQL protects portability.
    // SQLite must not creep in as a substitute for real integration tests.
    expect(config('database.default'))->toBeIn(['mariadb', 'pgsql'])
        ->and(config('database.connections.mariadb.driver'))->toBe('mariadb')
        ->and(config('database.connections.pgsql.driver'))->toBe('pgsql');
});

it('does not require Redis by default', function () {
    // phpunit.xml overrides queue/cache/session for tests, so assert the
    // real development defaults that ship in .env.example instead.
    $example = file_get_contents(base_path('.env.example'));
    assert(is_string($example));

    foreach (['QUEUE_CONNECTION' => 'database', 'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'database'] as $key => $expected) {
        expect($example)->toContain("{$key}={$expected}");
    }

    expect($example)->not->toMatch('/^(QUEUE_CONNECTION|CACHE_STORE|SESSION_DRIVER)=redis/m');
});

it('keeps the automated gate off the public network, and production on the real breach check', function () {
    // Development and CI (which copy .env.example) and the test suite use the no-op checker, so nothing
    // in ordinary validation needs the public breached-password service. The code default is the real
    // one, so a production host that sets nothing is still screened, and `none` is refused there.
    $example = file_get_contents(base_path('.env.example'));
    $phpunit = file_get_contents(base_path('phpunit.xml'));
    assert(is_string($example) && is_string($phpunit));

    expect($example)->toMatch('/^IDENTITY_COMPROMISED_PASSWORD_CHECK=none$/m')
        ->and($phpunit)->toContain('<env name="IDENTITY_COMPROMISED_PASSWORD_CHECK" value="none"/>');

    $config = file_get_contents(base_path('config/identity.php'));
    assert(is_string($config));
    expect($config)->toContain("env('IDENTITY_COMPROMISED_PASSWORD_CHECK', 'pwned_passwords')");
});

it('ships the same-origin development topology with no CORS allow-list', function () {
    // ADR 0016: the Console and the API share one origin, so nothing needs CORS.
    // A non-empty default would silently grant cross-origin browser access.
    $example = file_get_contents(base_path('.env.example'));
    assert(is_string($example));

    expect($example)->toContain('APP_URL=http://commons.flowlife.localhost:');
    expect($example)->toMatch('/^CORS_ALLOWED_ORIGINS=$/m');
    expect($example)->not->toContain('guardian.flowlife.localhost');
    expect($example)->not->toContain('api.flowlife.localhost');
});
