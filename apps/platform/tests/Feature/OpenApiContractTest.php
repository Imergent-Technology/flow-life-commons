<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

use function Pest\Laravel\getJson;

/**
 * @return array<string, mixed>
 */
function openApiSpec(): array
{
    $spec = Yaml::parseFile(base_path('openapi/openapi.yaml'));
    assert(is_array($spec));

    /** @var array<string, mixed> $spec */
    return $spec;
}

/**
 * "METHOD /path" keys for the spec, with path params normalised to {}.
 *
 * @return list<string>
 */
function specOperations(): array
{
    $paths = openApiSpec()['paths'] ?? [];
    assert(is_array($paths));
    $operations = [];

    foreach ($paths as $path => $item) {
        assert(is_string($path) && is_array($item));
        foreach (array_keys($item) as $method) {
            if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                $operations[] = strtoupper((string) $method).' '.preg_replace('/\{[^}]+\}/', '{}', $path);
            }
        }
    }

    sort($operations);

    return $operations;
}

/**
 * @return list<string>
 */
function routeOperations(): array
{
    $operations = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }

        $path = '/'.substr($route->uri(), strlen('api/v1/'));

        foreach ($route->methods() as $method) {
            assert(is_string($method));

            if ($method !== 'HEAD' && $method !== 'OPTIONS') {
                $operations[] = $method.' '.preg_replace('/\{[^}]+\}/', '{}', $path);
            }
        }
    }

    sort($operations);

    return $operations;
}

it('declares OpenAPI 3.1 with basic metadata', function () {
    $spec = openApiSpec();

    expect($spec['openapi'])->toBeString()->toStartWith('3.1')
        ->and($spec['info'])->toBeArray()->toHaveKeys(['title', 'version']);
});

it('documents exactly the routes served under /api/v1', function () {
    expect(routeOperations())->toBe(specOperations());
});

it('serves a health response matching the documented schema', function () {
    $components = openApiSpec()['components'];
    assert(is_array($components) && is_array($components['schemas']));
    $schema = $components['schemas']['Health'];
    assert(is_array($schema) && is_array($schema['required']));

    $body = getJson('/api/v1/health')->assertOk()->json();
    assert(is_array($body));

    expect(array_keys($body))->toEqualCanonicalizing($schema['required']);
});
