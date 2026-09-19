<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;

use function Pest\Laravel\get;
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

it('renders unknown API routes as JSON', function () {
    get('/api/v1/nope')->assertNotFound()->assertHeader('Content-Type', 'application/json');
});

it('allows configured browser origins and no others (CORS)', function () {
    withHeaders(['Origin' => 'http://guardian.test'])
        ->getJson('/api/v1/health')
        ->assertHeader('Access-Control-Allow-Origin', 'http://guardian.test');

    withHeaders(['Origin' => 'http://evil.test'])
        ->getJson('/api/v1/health')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
