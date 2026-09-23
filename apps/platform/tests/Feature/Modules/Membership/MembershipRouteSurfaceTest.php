<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Api;

/*
 * The Membership route surface is CLOSED (Work Package 7): exactly the five operator routes Work Package 5 approved, each
 * behind the Commons human session, and nothing member-facing, WordPress-facing, public or bearer-authenticated. A new
 * Membership route fails here until this contract is updated on purpose, in the same change.
 *
 * Found by what the runtime serves, not by reading one routes file: a route added in another module's routes file, or
 * pointing at a Membership controller from anywhere, is still found.
 */

/** @return array<string, list<string>> "METHOD uri" => middleware, for every route that is Membership's. */
function membershipRoutes(): array
{
    $found = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $controller = $route->getControllerClass() ?? '';
        $concernsMembership = str_starts_with($controller, 'App\\Modules\\Membership\\')
            || preg_match('/member|membership/i', $route->uri()) === 1;
        if (! $concernsMembership) {
            continue;
        }
        foreach (array_diff(Api::strings($route->methods()), ['HEAD', 'OPTIONS']) as $method) {
            $found["{$method} {$route->uri()}"] = Api::strings($route->gatherMiddleware());
        }
    }
    ksort($found);

    return $found;
}

const APPROVED_MEMBERSHIP_ROUTES = [
    'GET api/v1/admin/members' => 'membership.records.view',
    'GET api/v1/admin/members/{person}' => 'membership.records.view',
    'POST api/v1/admin/members' => 'membership.records.manage',
    'POST api/v1/admin/members/{person}/grants' => 'membership.records.manage',
    'POST api/v1/admin/membership-grants/{grant}/revoke' => 'membership.records.manage',
];

it('serves exactly the five approved operator routes, and no other route touches Membership', function () {
    $routes = array_keys(membershipRoutes());
    $approved = array_keys(APPROVED_MEMBERSHIP_ROUTES);
    sort($approved);

    expect($routes)->toBe($approved);
});

it('puts every Membership route behind the Commons human session and one Membership capability', function () {
    $problems = [];
    foreach (membershipRoutes() as $route => $middleware) {
        $capabilities = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'can:') && $m !== 'can:console.access'));
        $auth = array_values(array_filter($middleware, fn (string $m): bool => $m === 'auth' || str_starts_with($m, 'auth:')));
        $mutation = str_starts_with($route, 'POST ');

        foreach (['stateful', 'auth:web', 'can:console.access'] as $required) {
            if (! in_array($required, $middleware, true)) {
                $problems[] = "{$route} lacks {$required}";
            }
        }
        // `auth:web` is the session guard, and it is the only authenticator on the route: no second, weaker way in.
        if ($auth !== ['auth:web']) {
            $problems[] = "{$route} authenticates with ".implode(', ', $auth);
        }
        if ($capabilities !== ['can:'.(APPROVED_MEMBERSHIP_ROUTES[$route] ?? '?')]) {
            $problems[] = "{$route} checks ".implode(', ', $capabilities);
        }
        if ($mutation !== in_array('security.verified', $middleware, true)) {
            $problems[] = "{$route} ".($mutation ? 'lacks' : 'wrongly has').' security.verified';
        }
    }

    expect($problems)->toBe([]);
});

it('has no member-facing, WordPress-facing, public or client-authenticated route anywhere under /api/v1', function () {
    $problems = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }
        if (preg_match('#^api/v1/(?:members?|membership|my|me/membership|wordpress|wp)(?:/|$)#i', $uri) === 1) {
            $problems[] = "{$uri} is a member- or WordPress-facing path";
        }
        foreach (Api::strings($route->gatherMiddleware()) as $middleware) {
            // The platform has exactly one authenticator, the session guard; anything else would be a second way in.
            if ((str_starts_with($middleware, 'auth:') && $middleware !== 'auth:web') || preg_match('/bearer|token|client|signature|hmac|jwt/i', $middleware) === 1) {
                $problems[] = "{$uri} uses {$middleware}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('configures one authentication guard, the Console session, with nothing that authenticates a client or a token', function () {
    $guards = config('auth.guards');
    assert(is_array($guards));

    // Phase-1 fact (ADR 0018 is accepted but not built): when service clients or delegated persons are implemented, this
    // is expected to change deliberately, with the new guard named here.
    expect(array_keys($guards))->toBe(['web'])
        ->and(Api::map($guards['web']))->toBe(['driver' => 'session', 'provider' => 'accounts'])
        ->and(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.defaults.passwords'))->toBe('accounts');

    // Laravel merges its own default `users` provider and broker into the runtime config, although config/auth.php
    // declares only `accounts`. They are inert: no guard authenticates through them, and the model they name does not
    // exist, so they cannot yield an authenticated user. Pinned so that changes if anyone wires them up.
    foreach ($guards as $guard) {
        expect(Api::map($guard)['provider'])->toBe('accounts');
    }
    expect(class_exists('App\\Models\\User'))->toBeFalse();
});

it('documents exactly the same Membership operations in the OpenAPI contract, each as a session-authenticated operator call', function () {
    $spec = Yaml::parseFile(base_path('openapi/openapi.yaml'));
    assert(is_array($spec));
    $documented = [];
    foreach (Api::map($spec['paths']) as $path => $item) {
        if (preg_match('/member/i', $path) !== 1) {
            continue;
        }
        foreach (Api::map($item) as $method => $operation) {
            if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            $operation = Api::map($operation);
            $documented[strtoupper($method).' api/v1'.$path] = [
                $operation['security'] ?? null,
                $operation['x-required-capability'] ?? null,
            ];
        }
    }
    ksort($documented);

    $expected = [];
    foreach (APPROVED_MEMBERSHIP_ROUTES as $route => $capability) {
        $expected[$route] = [[['sessionCookie' => []]], $capability];
    }
    ksort($expected);

    expect($documented)->toBe($expected);
});

it('would see a route added to Membership: the enumeration is not blind (positive control)', function () {
    Route::middleware('api')->get('api/v1/member/me', fn () => ['ok' => true]);
    Route::middleware('api')->get('api/v1/wordpress/members/{person}', fn () => ['ok' => true]);
    Route::getRoutes()->refreshNameLookups();

    expect(array_keys(membershipRoutes()))->toContain('GET api/v1/member/me')
        ->toContain('GET api/v1/wordpress/members/{person}');
});
