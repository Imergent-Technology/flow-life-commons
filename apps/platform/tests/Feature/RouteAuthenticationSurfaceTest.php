<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Tests\Support\Api;
use Tests\Support\RouteSurface;

/*
 * G6 WP2-01: the whole application's authentication surface.
 *
 * `AdministrationRoutesTest` pins every `/admin` route's capability and step-up. This pins the rest: every
 * route Laravel registered is either authenticated or one of ten reviewed public routes, each public route
 * works the way its entry says, and OpenAPI agrees. docs/architecture/trust-boundaries.md is the prose.
 */

/**
 * A route as a module's routes.php would register it, but not added to the application.
 *
 * @param  list<string>  $methods
 * @param  list<string>  $middleware
 * @param  list<string>  $withoutMiddleware
 */
function surfaceRoute(array $methods, string $uri, array $middleware = [], array $withoutMiddleware = []): LaravelRoute
{
    $route = new LaravelRoute($methods, $uri, fn () => 'ok');
    $route->middleware($middleware);
    if ($withoutMiddleware !== []) {
        $route->withoutMiddleware($withoutMiddleware);
    }

    return $route;
}

/** @return list<LaravelRoute> */
function surfaceRegistered(): array
{
    return array_values(Route::getRoutes()->getRoutes());
}

/**
 * The route table without the routes that answer `$key`, so a test can put its own version in their place.
 *
 * @param  list<LaravelRoute>  $routes
 * @return list<LaravelRoute>
 */
function surfaceWithout(string $key, array $routes): array
{
    return array_values(array_filter($routes, fn (LaravelRoute $route): bool => ! in_array($key, RouteSurface::keys($route), true)));
}

/** @param list<string> $problems */
function surfaceMentions(array $problems, string $needle): bool
{
    foreach ($problems as $problem) {
        if (str_contains($problem, $needle)) {
            return true;
        }
    }

    return false;
}

it('registers exactly the reviewed public routes without authentication, and authenticates every other route', function () {
    $problems = RouteSurface::violations(surfaceRegistered(), RouteSurface::PUBLIC_ROUTES, RouteSurface::specPaths());

    expect($problems)->toBe([]);
});

it('lists exactly ten public routes, by how each works', function () {
    $byKind = [];
    foreach (RouteSurface::PUBLIC_ROUTES as $key => $entry) {
        $byKind[$entry['kind']][] = $key;
        expect($entry['reason'])->not->toBe('');
    }
    ksort($byKind);

    expect(count(RouteSurface::PUBLIC_ROUTES))->toBe(10)
        ->and($byKind['framework'])->toBe(['GET up'])
        ->and($byKind['stateless'])->toBe(['GET api/v1/health', 'POST api/v1/invitations/accept', 'POST api/v1/password/forgot', 'POST api/v1/password/reset'])
        ->and($byKind['session'])->toBe(['POST api/v1/login', 'POST api/v1/mfa/challenge', 'POST api/v1/mfa/enrollment', 'POST api/v1/mfa/enrollment/confirm', 'POST api/v1/logout']);
});

it('walks every route: the liveness probe is the only route outside /api/v1', function () {
    $outside = [];
    foreach (surfaceRegistered() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            array_push($outside, ...RouteSurface::keys($route));
        }
    }

    expect($outside)->toBe(['GET up']);
});

it('reads a route\'s effective middleware: aliases and groups resolved, exclusions applied', function () {
    $stateful = RouteSurface::middleware(surfaceRoute(['GET'], 'api/v1/x', ['stateful', 'auth:web']));
    $excluded = RouteSurface::middleware(surfaceRoute(['GET'], 'api/v1/x', ['stateful', 'auth:web'], ['auth:web']));
    $bare = RouteSurface::middleware(surfaceRoute(['GET'], 'api/v1/x'));

    expect($stateful)->toContain(RouteSurface::AUTHENTICATE)
        ->and($stateful)->toContain(StartSession::class)
        ->and($stateful)->toContain(PreventRequestForgery::class)
        ->and($excluded)->not->toContain(RouteSurface::AUTHENTICATE)
        ->and($excluded)->toContain(StartSession::class)
        ->and($bare)->not->toContain(RouteSurface::AUTHENTICATE)
        ->and($bare)->not->toContain(StartSession::class);
});

it('reports a real route that loses authentication (GET /me without auth:web)', function () {
    $weakened = surfaceRoute(['GET', 'HEAD'], 'api/v1/me', ['stateful']);

    $problems = RouteSurface::violations([...surfaceWithout('GET api/v1/me', surfaceRegistered()), $weakened], RouteSurface::PUBLIC_ROUTES, RouteSurface::specPaths());

    expect($problems)->toContain('GET api/v1/me is not authenticated and is not in the public list')
        ->and(surfaceMentions($problems, 'OpenAPI'))->toBeFalse();
});

it('reports an unreviewed route added to the session surface without auth:web', function () {
    $stray = surfaceRoute(['POST'], 'api/v1/preferences', ['stateful']);

    $problems = RouteSurface::violations([...surfaceRegistered(), $stray], RouteSurface::PUBLIC_ROUTES, RouteSurface::specPaths());

    expect($problems)->toContain('POST api/v1/preferences is not authenticated and is not in the public list');
});

it('reports a route that excludes authentication through withoutMiddleware', function () {
    $verify = surfaceRoute(['POST'], 'api/v1/security/verify', ['stateful', 'auth:web'], ['auth:web']);

    $problems = RouteSurface::violations([...surfaceWithout('POST api/v1/security/verify', surfaceRegistered()), $verify], RouteSurface::PUBLIC_ROUTES, RouteSurface::specPaths());

    expect($problems)->toContain('POST api/v1/security/verify is not authenticated and is not in the public list');
});

it('reports a pending sign-in route that loses the session surface', function () {
    $login = surfaceRoute(['POST'], 'api/v1/mfa/challenge', ['api']);

    $problems = RouteSurface::violations([...surfaceWithout('POST api/v1/mfa/challenge', surfaceRegistered()), $login], RouteSurface::PUBLIC_ROUTES, RouteSurface::specPaths());

    expect($problems)->toContain("POST api/v1/mfa/challenge is listed as 'session' but lacks StartSession or PreventRequestForgery");
});

it('reports a stateless public route that starts a session', function () {
    $health = surfaceRoute(['GET', 'HEAD'], 'api/v1/health', ['stateful']);

    $problems = RouteSurface::violations([...surfaceWithout('GET api/v1/health', surfaceRegistered()), $health], RouteSurface::PUBLIC_ROUTES, RouteSurface::specPaths());

    expect($problems)->toContain("GET api/v1/health is listed as 'stateless' but starts a session, cookies or CSRF handling");
});

it('reports a stale entry in the public list', function () {
    $public = RouteSurface::PUBLIC_ROUTES + ['POST api/v1/legacy/token' => ['kind' => 'stateless', 'openapi' => 'none', 'reason' => 'Removed route']];

    $problems = RouteSurface::violations(surfaceRegistered(), $public, RouteSurface::specPaths());

    expect($problems)->toBe(['POST api/v1/legacy/token is in the public list but no such route is registered (stale entry)']);
});

it('reports a public-list entry whose route has since become authenticated', function () {
    $health = surfaceRoute(['GET', 'HEAD'], 'api/v1/health', ['stateful', 'auth:web']);

    $problems = RouteSurface::violations([...surfaceWithout('GET api/v1/health', surfaceRegistered()), $health], RouteSurface::PUBLIC_ROUTES, RouteSurface::specPaths());

    expect($problems)->toContain('GET api/v1/health is authenticated but is still in the public list (stale entry)');
});

it('reports a route registered outside /api/v1, however it is protected', function () {
    $external = surfaceRoute(['GET', 'HEAD'], 'storage/{path}', []);
    $guarded = surfaceRoute(['GET', 'HEAD'], 'debug', ['stateful', 'auth:web']);

    $problems = RouteSurface::violations([...surfaceRegistered(), $external, $guarded], RouteSurface::PUBLIC_ROUTES, RouteSurface::specPaths());

    expect($problems)->toContain('GET storage/{path} is not authenticated and is not in the public list')
        ->and($problems)->toContain('GET debug is outside /api/v1 and has not been reviewed');
});

it('reports a framework entry that is anything but the liveness probe', function () {
    $public = RouteSurface::PUBLIC_ROUTES;
    $public['GET api/v1/health']['kind'] = 'framework';

    $problems = RouteSurface::violations(surfaceRegistered(), $public, RouteSurface::specPaths());

    expect($problems)->toContain("GET api/v1/health is listed as 'framework' but only the 'GET up' probe may be, and without a session");
});

it('reports an authenticated route without the session surface', function () {
    $me = surfaceRoute(['GET', 'HEAD'], 'api/v1/me', ['auth:web']);

    $problems = RouteSurface::violations([...surfaceWithout('GET api/v1/me', surfaceRegistered()), $me], RouteSurface::PUBLIC_ROUTES, RouteSurface::specPaths());

    expect($problems)->toContain('GET api/v1/me is authenticated but not on the session surface (StartSession and PreventRequestForgery)');
});

it('ties OpenAPI to the public list in both directions', function () {
    $paths = RouteSurface::specPaths();

    // A public route documented as requiring the session cookie.
    $login = Api::map(Api::map($paths['/login'])['post']);
    $strict = $paths;
    $strict['/login'] = ['post' => ['security' => [['sessionCookie' => []]]] + $login];
    expect(RouteSurface::violations(surfaceRegistered(), RouteSurface::PUBLIC_ROUTES, $strict))
        ->toBe(["POST api/v1/login is listed as openapi 'none' but OpenAPI documents it as 'required'"]);

    // An authenticated route documented as public (and, separately, an operation the public list does not know).
    $me = Api::map(Api::map($paths['/me'])['get']);
    $open = $paths;
    $open['/me'] = ['get' => ['security' => []] + $me];
    $problems = RouteSurface::violations(surfaceRegistered(), RouteSurface::PUBLIC_ROUTES, $open);
    expect($problems)->toContain("GET api/v1/me is authenticated but OpenAPI documents it as 'none'")
        ->and($problems)->toContain('GET api/v1/me is documented without required authentication but is not in the public list');

    // Logout is documented as the session cookie OR nothing; claiming either extreme is a mismatch.
    $logout = Api::map(Api::map($paths['/logout'])['post']);
    $plain = $paths;
    $plain['/logout'] = ['post' => ['security' => [['sessionCookie' => []]]] + $logout];
    expect(RouteSurface::violations(surfaceRegistered(), RouteSurface::PUBLIC_ROUTES, $plain))
        ->toBe(["POST api/v1/logout is listed as openapi 'optional' but OpenAPI documents it as 'required'"]);
});

it('reads an operation\'s documented authentication', function () {
    expect(RouteSurface::documentedAuthentication([]))->toBe('none')
        ->and(RouteSurface::documentedAuthentication(['security' => []]))->toBe('none')
        ->and(RouteSurface::documentedAuthentication(['security' => [['sessionCookie' => []]]]))->toBe('required')
        ->and(RouteSurface::documentedAuthentication(['security' => [['sessionCookie' => []], []]]))->toBe('optional')
        ->and(RouteSurface::documentedAuthentication(['security' => [[]]]))->toBe('none')
        ->and(RouteSurface::documentedAuthentication(['security' => [['somethingElse' => []]]]))->toBe('required');
});

it('accepts a hand-built surface that follows the rules', function () {
    $routes = [
        surfaceRoute(['GET', 'HEAD'], 'api/v1/me', ['stateful', 'auth:web']),
        surfaceRoute(['GET', 'HEAD'], 'api/v1/health'),
    ];
    $public = ['GET api/v1/health' => ['kind' => 'stateless', 'openapi' => 'none', 'reason' => 'x']];
    $paths = ['/me' => ['get' => ['security' => [['sessionCookie' => []]]]], '/health' => ['get' => []]];

    expect(RouteSurface::violations($routes, $public, $paths))->toBe([]);
});
