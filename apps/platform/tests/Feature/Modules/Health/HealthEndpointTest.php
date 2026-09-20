<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

it('reports the platform as healthy', function () {
    getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'service' => 'flowlife-platform',
            'api_version' => 'v1',
            'checks' => ['database' => 'ok'],
        ]);
});

it('reports degraded with 503 when the database is unreachable', function () {
    $database = Mockery::mock(ConnectionInterface::class);
    $database->shouldReceive('select')->andThrow(new RuntimeException('connection refused'));
    app()->instance(ConnectionInterface::class, $database);

    getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'degraded',
            'service' => 'flowlife-platform',
            'api_version' => 'v1',
            'checks' => ['database' => 'fail'],
        ]);
});

it('does not require authentication', function () {
    getJson('/api/v1/health')->assertOk();
});

it('renders unknown API routes as JSON, even to a browser', function (string $path) {
    // The gateway sends everything under /api to Laravel, so an unknown API path
    // must never look like (or fall back to) the Console's HTML.
    withHeaders(['Accept' => 'text/html'])
        ->get($path)
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json');
})->with(['/api', '/api/nope', '/api/v1/nope']);

it('allows only explicitly configured external origins (CORS)', function () {
    // phpunit.xml configures partner.test to exercise the mechanism. The Guardian
    // Console is same-origin (ADR 0016) and is deliberately not in any allow-list.
    withHeaders(['Origin' => 'http://partner.test'])
        ->getJson('/api/v1/health')
        ->assertHeader('Access-Control-Allow-Origin', 'http://partner.test');

    withHeaders(['Origin' => 'http://evil.test'])
        ->getJson('/api/v1/health')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('never enables credentialed cross-origin access', function () {
    withHeaders(['Origin' => 'http://partner.test'])
        ->getJson('/api/v1/health')
        ->assertHeaderMissing('Access-Control-Allow-Credentials');

    expect(config('cors.supports_credentials'))->toBeFalse();
});
