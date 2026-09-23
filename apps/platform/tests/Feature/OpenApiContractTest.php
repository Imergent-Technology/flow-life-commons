<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Api;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;

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

it('serves signed-in account responses matching the documented schema', function () {
    $schemas = openApiSpec()['components'];
    assert(is_array($schemas) && is_array($schemas['schemas']) && is_array($schemas['schemas']['CurrentAccount']));
    $current = $schemas['schemas']['CurrentAccount'];
    assert(is_array($current['required']) && is_array($current['properties']));

    Identity::savedActiveAccount();
    $console = new Console;
    $login = $console->login('ada@example.org', Identity::PASSWORD)->assertOk()->json();
    $me = $console->me()->assertOk()->json();

    foreach ([$login, $me] as $body) {
        assert(is_array($body));
        expect(array_keys($body))->toEqualCanonicalizing($current['required']);

        foreach ($current['properties'] as $name => $property) {
            assert(is_array($property) && is_array($body[$name]));
            if ($property['type'] === 'array') {
                expect(array_is_list($body[$name]))->toBeTrue();

                continue;
            }
            assert(is_array($property['required']));
            expect(array_keys($body[$name]))->toEqualCanonicalizing($property['required']);
        }
    }
});

it('documents invitation acceptance as creating no session, and verifying the email ONLY for an emailed invitation', function () {
    $paths = openApiSpec()['paths'];
    assert(is_array($paths) && is_array($paths['/invitations/accept']) && is_array($paths['/invitations/accept']['post']));
    $operation = $paths['/invitations/accept']['post'];
    assert(is_array($operation['responses']) && is_array($operation['responses']['204']));
    $success = $operation['responses']['204'];
    $description = $operation['description'];
    assert(is_string($description) && is_string($success['description']));

    // The lifecycle is: accept (password set, account active, NO session), then the ordinary login. Whether the email
    // becomes verified depends on how the invitation was delivered (ADR 0024), and the contract says so both ways.
    expect($description)->toContain('does not sign the caller in')
        ->and($description)->toContain('depends on **how the invitation was')
        ->and($description)->toContain('**emailed**')
        ->and($description)->toContain('unverified')
        ->and($description)->not->toContain('Acceptance does **not** verify the email address')
        ->and($success)->not->toHaveKey('headers')
        ->and($success['description'])->toContain('No session was created')
        ->and($description)->not->toContain('new session');
});

it('documents each administration operation with the capability and the verification its route really enforces', function () {
    $spec = openApiSpec();
    $paths = $spec['paths'];
    assert(is_array($paths));
    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/admin/')) {
            continue;
        }
        $path = '/'.substr($route->uri(), strlen('api/v1/'));
        foreach (array_diff(Api::strings($route->methods()), ['HEAD', 'OPTIONS']) as $method) {
            $documented = Api::map($paths[$path] ?? [])[strtolower($method)] ?? null;
            assert(is_array($documented), "{$method} {$path} is not documented");
            $middleware = Api::strings($route->gatherMiddleware());
            $capability = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:') && $m !== 'can:console.access'));

            expect($documented['x-required-capability'])->toBe(substr($capability[0], 4), "{$method} {$path}")
                ->and($documented['x-requires-recent-verification'])->toBe(in_array('security.verified', $middleware, true), "{$method} {$path}")
                ->and($documented['security'])->toBe([['sessionCookie' => []]]);
            $checked++;
        }
    }

    expect($checked)->toBe(15);
});

it('never lets an administration schema name a secret', function () {
    $components = openApiSpec()['components'];
    assert(is_array($components) && is_array($components['schemas']));
    $names = [
        'ManagedAccount', 'ManagedAccountPage', 'RoleCatalog', 'InvitationResult', 'InviteOperatorRequest', 'GrantRoleRequest',
        'Member', 'MemberPage', 'MembershipGrant', 'MembershipGrantHistoryEntry', 'RegisterMemberRequest', 'GrantMembershipRequest',
    ];
    $text = strtolower(json_encode(array_intersect_key($components['schemas'], array_flip($names)), JSON_THROW_ON_ERROR));
    $text = str_replace('never a password', '', $text); // the description says what is NOT there

    foreach (['password_hash', 'token_hash', 'secret_ciphertext', 'code_hash', 'session_id', '"token"', '"password"', 'ciphertext'] as $forbidden) {
        expect($text)->not->toContain($forbidden);
    }
});

it('serves administration responses matching the documented ManagedAccount schema', function () {
    $schemas = openApiSpec()['components'];
    $schema = Api::map(Api::map(Api::map($schemas)['schemas'])['ManagedAccount']);
    $required = Api::strings($schema['required']);
    $properties = Api::map($schema['properties']);

    [$console] = Mfa::signedInAdmin();
    $body = Api::map($console->get('/api/v1/admin/accounts?q=admin@')->assertOk()->json('data.0'));

    expect(array_keys($body))->toEqualCanonicalizing($required)
        ->and(array_keys($properties))->toEqualCanonicalizing($required)
        ->and(array_keys(Api::map($body['mfa'])))->toEqualCanonicalizing(Api::strings(Api::map($properties['mfa'])['required']));
});
