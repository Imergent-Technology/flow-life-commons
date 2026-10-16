<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\Yaml\Yaml;

/**
 * The application's authentication surface, judged from the routes Laravel actually registered (G6 WP2-01).
 *
 * Every route is either authenticated (`auth:web`) or listed, with the reason, in {@see self::PUBLIC_ROUTES}.
 * A route is judged by its EFFECTIVE middleware: aliases and groups resolved, `withoutMiddleware` applied,
 * through the router's own resolver. Reading the route files would miss all three.
 *
 * `violations()` is a pure judgement over the routes and spec it is handed, so the tests can feed it
 * hand-built routes and prove that each kind of violation is reported.
 */
final class RouteSurface
{
    /** What `auth:web` resolves to. A route without it, after exclusions, is not authenticated. */
    public const AUTHENTICATE = 'Illuminate\Auth\Middleware\Authenticate:web';

    /**
     * The routes reviewed as allowed to be unauthenticated. Adding one is a security decision: it needs a reason
     * here, the documentation in docs/architecture/trust-boundaries.md, and an OpenAPI operation that says so.
     *
     * - `framework`: the liveness probe, outside /api/v1, with no middleware beyond the global headers.
     * - `stateless`: the `api` group only. No session, no cookie, no CSRF. A secret in the body is the credential.
     * - `session`: the `stateful` group (cookie, session, CSRF) but not `auth:web`: they work on a pending
     *   sign-in held in the session, or end a session that may not exist.
     * - `openapi`: how the operation must be documented. `none` is `security: []` (or no `security` at all);
     *   `optional` is `[sessionCookie, {}]`: authenticated when a session exists, anonymous otherwise.
     *
     * @var array<string, array{kind: 'framework'|'stateless'|'session', openapi: 'none'|'optional', reason: string}>
     */
    public const PUBLIC_ROUTES = [
        'GET up' => ['kind' => 'framework', 'openapi' => 'none', 'reason' => 'Liveness probe; answers up or down and nothing else'],
        'GET api/v1/health' => ['kind' => 'stateless', 'openapi' => 'none', 'reason' => 'Coarse pass or fail health for monitors'],
        'POST api/v1/invitations/accept' => ['kind' => 'stateless', 'openapi' => 'none', 'reason' => 'The invitation token in the body is the credential'],
        'POST api/v1/password/forgot' => ['kind' => 'stateless', 'openapi' => 'none', 'reason' => 'Starts a reset; answers identically for any address'],
        'POST api/v1/password/reset' => ['kind' => 'stateless', 'openapi' => 'none', 'reason' => 'The reset token in the body is the credential'],
        'POST api/v1/login' => ['kind' => 'session', 'openapi' => 'none', 'reason' => 'Proves the password; this is how a session starts'],
        'POST api/v1/mfa/challenge' => ['kind' => 'session', 'openapi' => 'none', 'reason' => 'Finishes a sign-in whose password was proved; works on the pending sign-in in the session'],
        'POST api/v1/mfa/enrollment' => ['kind' => 'session', 'openapi' => 'none', 'reason' => 'Starts authenticator enrolment of a pending sign-in'],
        'POST api/v1/mfa/enrollment/confirm' => ['kind' => 'session', 'openapi' => 'none', 'reason' => 'Confirms enrolment of a pending sign-in'],
        'POST api/v1/logout' => ['kind' => 'session', 'openapi' => 'optional', 'reason' => 'Idempotent: ends a session if there is one and answers 204 either way'],
    ];

    /**
     * The route's effective middleware, one string per entry (a closure is `Closure`).
     *
     * @return list<string>
     */
    public static function middleware(Route $route): array
    {
        // The HTTP kernel registers the framework's aliases (`auth`, `can`, ...) with the router when it is built.
        app(Kernel::class);
        $resolved = [];
        foreach (app('router')->gatherRouteMiddleware($route) as $middleware) {
            $resolved[] = is_string($middleware) ? $middleware : 'Closure';
        }

        return $resolved;
    }

    /**
     * "METHOD uri" for each method the route answers, except the HEAD Laravel adds to every GET.
     *
     * @return list<string>
     */
    public static function keys(Route $route): array
    {
        $keys = [];
        foreach (Api::strings($route->methods()) as $method) {
            if ($method !== 'HEAD') {
                $keys[] = $method.' '.$route->uri();
            }
        }

        return $keys;
    }

    /**
     * How an operation's `security` reads: `required` (the session cookie alone), `optional` (the cookie or
     * nothing), or `none` (`[]`, or no `security`, as the spec has no top-level default).
     *
     * @param  array<string, mixed>  $operation
     * @return 'required'|'optional'|'none'
     */
    public static function documentedAuthentication(array $operation): string
    {
        $security = $operation['security'] ?? [];
        assert(is_array($security));
        if ($security === []) {
            return 'none';
        }
        $requiresCookie = false;
        $allowsAnonymous = false;
        foreach ($security as $requirement) {
            assert(is_array($requirement));
            if ($requirement === []) {
                $allowsAnonymous = true;
            } elseif (array_keys($requirement) === ['sessionCookie']) {
                $requiresCookie = true;
            } else {
                return 'required'; // an unknown scheme is never read as weaker than required
            }
        }

        return $allowsAnonymous ? ($requiresCookie ? 'optional' : 'none') : 'required';
    }

    /**
     * @return array<string, mixed> the spec's `paths`
     */
    public static function specPaths(): array
    {
        $spec = Yaml::parseFile(base_path('openapi/openapi.yaml'));
        assert(is_array($spec) && is_array($spec['paths']));

        /** @var array<string, mixed> $paths */
        $paths = $spec['paths'];

        return $paths;
    }

    /**
     * Every way the routes break the rule. Empty means the surface is exactly the reviewed one.
     *
     * @param  iterable<Route>  $routes
     * @param  array<string, array{kind: string, openapi: string, reason: string}>  $public
     * @param  array<string, mixed>  $paths  the OpenAPI `paths`
     * @return list<string>
     */
    public static function violations(iterable $routes, array $public, array $paths): array
    {
        $problems = [];
        $registered = [];

        foreach ($routes as $route) {
            $middleware = self::middleware($route);
            $authenticated = in_array(self::AUTHENTICATE, $middleware, true);
            $session = in_array(StartSession::class, $middleware, true);
            $csrf = in_array(PreventRequestForgery::class, $middleware, true);
            $cookies = in_array(EncryptCookies::class, $middleware, true);

            foreach (self::keys($route) as $key) {
                $registered[$key] = true;
                $entry = $public[$key] ?? null;
                $documented = self::documentedFor($route, $key, $paths);

                if ($authenticated) {
                    if ($entry !== null) {
                        $problems[] = "{$key} is authenticated but is still in the public list (stale entry)";
                    }
                    if (! str_starts_with($route->uri(), 'api/v1/')) {
                        $problems[] = "{$key} is outside /api/v1 and has not been reviewed";
                    }
                    if (! $session || ! $csrf) {
                        $problems[] = "{$key} is authenticated but not on the session surface (StartSession and PreventRequestForgery)";
                    }
                    if ($documented !== 'required' && str_starts_with($route->uri(), 'api/v1/')) {
                        $problems[] = "{$key} is authenticated but OpenAPI documents it as '{$documented}'";
                    }

                    continue;
                }

                if ($entry === null) {
                    $problems[] = "{$key} is not authenticated and is not in the public list";

                    continue;
                }

                $kind = $entry['kind'];
                if ($kind === 'framework') {
                    if ($key !== 'GET up' || $session || $csrf || $cookies) {
                        $problems[] = "{$key} is listed as 'framework' but only the 'GET up' probe may be, and without a session";
                    }

                    continue;
                }
                if (! str_starts_with($route->uri(), 'api/v1/')) {
                    $problems[] = "{$key} is outside /api/v1 and has not been reviewed";
                }
                if ($kind === 'stateless' && ($session || $csrf || $cookies)) {
                    $problems[] = "{$key} is listed as 'stateless' but starts a session, cookies or CSRF handling";
                }
                if ($kind === 'session' && (! $session || ! $csrf)) {
                    $problems[] = "{$key} is listed as 'session' but lacks StartSession or PreventRequestForgery";
                }
                if ($documented !== $entry['openapi']) {
                    $problems[] = "{$key} is listed as openapi '{$entry['openapi']}' but OpenAPI documents it as '{$documented}'";
                }
            }
        }

        foreach (array_keys($public) as $key) {
            if (! isset($registered[$key])) {
                $problems[] = "{$key} is in the public list but no such route is registered (stale entry)";
            }
        }

        // The reverse of the OpenAPI tie-in: an operation documented as anything but "session required"
        // must be a reviewed public route, whatever the route's own middleware says.
        foreach ($paths as $path => $item) {
            assert(is_array($item));
            foreach ($item as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }
                assert(is_array($operation));
                /** @var array<string, mixed> $operation */
                $key = strtoupper($method).' api/v1'.$path;
                if (self::documentedAuthentication($operation) !== 'required' && ! isset($public[$key])) {
                    $problems[] = "{$key} is documented without required authentication but is not in the public list";
                }
            }
        }

        sort($problems);

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $paths
     * @return 'required'|'optional'|'none'|'undocumented'
     */
    private static function documentedFor(Route $route, string $key, array $paths): string
    {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            return 'none'; // not part of the API contract
        }
        $item = $paths['/'.substr($route->uri(), strlen('api/v1/'))] ?? null;
        $method = strtolower(explode(' ', $key)[0]);
        $operation = is_array($item) ? ($item[$method] ?? null) : null;
        if (! is_array($operation)) {
            return 'undocumented';
        }
        /** @var array<string, mixed> $operation */

        return self::documentedAuthentication($operation);
    }
}
