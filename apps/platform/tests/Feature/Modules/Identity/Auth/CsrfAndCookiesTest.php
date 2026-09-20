<?php

declare(strict_types=1);

use App\Modules\Identity\Http\EnforceAbsoluteSessionLifetime;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Console;
use Tests\Support\Identity;

use function Pest\Laravel\withHeaders;

/** @param  TestResponse<Response>  $response */
function cookieNamed(TestResponse $response, string $name): Cookie
{
    foreach ($response->baseResponse->headers->getCookies() as $cookie) {
        if ($cookie->getName() === $name) {
            return $cookie;
        }
    }

    throw new RuntimeException("The response set no {$name} cookie.");
}

/**
 * @param  TestResponse<Response>  $response
 * @return list<string> the raw Set-Cookie header lines, lower-cased
 */
function rawSetCookies(TestResponse $response): array
{
    return array_map(fn (?string $line): string => strtolower((string) $line), $response->headers->all('set-cookie'));
}

// --- The production cookie (ADR 0016) -------------------------------------------------

it('issues the session cookie exactly as the frozen design requires', function () {
    $response = (new Console)->bootstrap();
    $cookie = cookieNamed($response, '__Host-flowlife-session');

    expect($cookie->getName())->toStartWith('__Host-')
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->getSameSite())->toBe('lax');

    // ...and in the header a browser actually parses: no Domain attribute at all.
    $header = collect(rawSetCookies($response))->first(fn (string $h): bool => str_starts_with($h, '__host-flowlife-session='));
    expect($header)->toContain('; secure')->toContain('; httponly')->toContain('; path=/')->toContain('; samesite=lax')
        ->and($header)->not->toContain('domain');
});

it('issues the XSRF-TOKEN cookie readable by JavaScript but host-only', function () {
    $response = (new Console)->bootstrap();
    $cookie = cookieNamed($response, 'XSRF-TOKEN');

    // Readable by design (the Console echoes it in X-XSRF-TOKEN); it must still never be Domain-scoped.
    expect($cookie->isHttpOnly())->toBeFalse()
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('keeps the cookie attributes fixed in configuration', function () {
    expect(config('session.cookie'))->toBe('__Host-flowlife-session')
        ->and(config('session.secure'))->toBeTrue()
        ->and(config('session.http_only'))->toBeTrue()
        ->and(config('session.path'))->toBe('/')
        ->and(config('session.domain'))->toBeNull()
        ->and(config('session.same_site'))->toBe('lax')
        ->and(config('session.partitioned'))->toBeFalse()
        ->and(config('session.expire_on_close'))->toBeFalse()
        ->and(config('session.driver'))->toBe('database')
        ->and(config('session.lifetime'))->toBe(30);
});

it('lets no environment variable weaken the cookie attributes', function () {
    $weakening = [
        'SESSION_COOKIE' => 'evil-session',
        'SESSION_SECURE_COOKIE' => 'false',
        'SESSION_DOMAIN' => '.flowlifeglobal.org',
        'SESSION_PATH' => '/api',
        'SESSION_SAME_SITE' => 'none',
        'SESSION_HTTP_ONLY' => 'false',
        'SESSION_EXPIRE_ON_CLOSE' => 'true',
        'SESSION_PARTITIONED_COOKIE' => 'true',
    ];
    foreach ($weakening as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require config_path('session.php');
    } finally {
        foreach (array_keys($weakening) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    expect($config['cookie'])->toBe('__Host-flowlife-session')
        ->and($config['secure'])->toBeTrue()
        ->and($config['domain'])->toBeNull()
        ->and($config['path'])->toBe('/')
        ->and($config['same_site'])->toBe('lax')
        ->and($config['http_only'])->toBeTrue()
        ->and($config['expire_on_close'])->toBeFalse()
        ->and($config['partitioned'])->toBeFalse();
});

// --- CSRF ------------------------------------------------------------------------------

it('accepts a state-changing request that carries the CSRF token', function () {
    Identity::savedActiveAccount();

    (new Console)->login('ada@example.org', Identity::PASSWORD)->assertOk();
});

it('accepts the plain token in X-CSRF-TOKEN as well as the cookie value in X-XSRF-TOKEN', function () {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->bootstrap();

    $console->post('/api/v1/login', ['email' => 'ada@example.org', 'password' => Identity::PASSWORD], ['X-CSRF-TOKEN' => $console->csrfToken()], withXsrfHeader: false)->assertOk();
});

it('rejects a state-changing request with no CSRF token', function () {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->bootstrap();

    $console->post('/api/v1/login', ['email' => 'ada@example.org', 'password' => Identity::PASSWORD], withXsrfHeader: false)->assertStatus(419);

    expect($console->me()->status())->toBe(401);
});

it('rejects a wrong, garbled or another session\'s CSRF token', function (string $kind) {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->bootstrap();
    $other = new Console;
    $other->bootstrap();

    $header = match ($kind) {
        'garbage' => 'not-a-token',
        'empty' => '',
        'plain (unencrypted) value in the encrypted slot' => $console->csrfToken(),
        "another session's token" => (string) $other->cookie('XSRF-TOKEN')?->getValue(),
        default => throw new InvalidArgumentException($kind),
    };

    $console->post('/api/v1/login', ['email' => 'ada@example.org', 'password' => Identity::PASSWORD], ['X-XSRF-TOKEN' => $header], withXsrfHeader: false)->assertStatus(419);
})->with(['garbage', 'empty', 'plain (unencrypted) value in the encrypted slot', "another session's token"]);

it('rejects a cross-site request that has no token, whatever Origin it claims', function () {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->bootstrap();

    $console->post('/api/v1/login', ['email' => 'ada@example.org', 'password' => Identity::PASSWORD], ['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://evil.example'], withXsrfHeader: false)->assertStatus(419);
    $console->post('/api/v1/login', ['email' => 'ada@example.org', 'password' => Identity::PASSWORD], ['Sec-Fetch-Site' => 'same-site'], withXsrfHeader: false)->assertStatus(419);
});

it('trusts the browser-set Sec-Fetch-Site: same-origin as Laravel 13 does, token or not', function () {
    // Pinned so a change is noticed: Laravel 13's PreventRequestForgery accepts a request the
    // browser itself marks same-origin. Page scripts cannot set or forge this header, and a
    // sibling host such as WordPress arrives as "same-site", which is refused above.
    Identity::savedActiveAccount();
    $console = new Console;
    $console->bootstrap();

    $console->post('/api/v1/login', ['email' => 'ada@example.org', 'password' => Identity::PASSWORD], ['Sec-Fetch-Site' => 'same-origin'], withXsrfHeader: false)->assertOk();
});

it('requires the token to sign out too', function () {
    Identity::savedActiveAccount();
    $console = new Console;
    $console->login('ada@example.org', Identity::PASSWORD)->assertOk();

    $console->post('/api/v1/logout', withXsrfHeader: false)->assertStatus(419);

    $console->me()->assertOk(); // still signed in: the forged logout did nothing
});

it('needs no token for a read', function () {
    (new Console)->get('/api/v1/me')->assertUnauthorized();
});

it('gives the middleware the order session, CSRF, absolute lifetime, authentication', function () {
    (new Console)->bootstrap(); // the kernel syncs middleware groups into the router on the first request
    $router = app('router');
    $route = $router->getRoutes()->getByName('api.v1.me');
    assert($route !== null);

    $order = array_values(array_map(
        fn (mixed $m): string => explode(':', is_string($m) ? $m : 'closure')[0],
        $router->resolveMiddleware($router->gatherRouteMiddleware($route)),
    ));
    $position = function (string $class) use ($order): int {
        $index = array_search($class, $order, true);
        assert(is_int($index), "{$class} is not in the route's middleware");

        return $index;
    };

    $session = $position(StartSession::class);
    $csrf = $position(PreventRequestForgery::class);
    $absolute = $position(EnforceAbsoluteSessionLifetime::class);
    $auth = $position(Authenticate::class);

    expect($session)->toBeLessThan($csrf)
        ->and($csrf)->toBeLessThan($absolute)
        ->and($absolute)->toBeLessThan($auth);
});

// --- CORS is not involved --------------------------------------------------------------

it('works without CORS: same-origin Console requests carry no Origin and need no CORS headers', function () {
    Identity::savedActiveAccount();
    $console = new Console;

    $response = $console->login('ada@example.org', Identity::PASSWORD);

    $response->assertOk();
    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
        ->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
});

it('grants no cross-origin browser access to the session endpoints', function () {
    $preflight = withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])
        ->options('/api/v1/login');
    $actual = withHeaders(['Origin' => 'https://evil.example'])->postJson('/api/v1/login', ['email' => 'a@example.org', 'password' => 'x']);

    foreach ([$preflight, $actual] as $response) {
        expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse()
            ->and($response->headers->has('Access-Control-Allow-Credentials'))->toBeFalse();
    }
    expect(config('cors.supports_credentials'))->toBeFalse();
});

// --- Statelessness of the rest of the API ------------------------------------------------

it('keeps the rest of the API stateless: no cookie and no session row', function () {
    Route::middleware('api')->get('/api/v1/zz-public', fn () => response()->json(['ok' => true]));

    foreach (['/api/v1/health', '/api/v1/nope', '/api/v1/zz-public'] as $path) {
        $response = Pest\Laravel\getJson($path);
        expect($response->headers->getCookies())->toBe([], "Set-Cookie on {$path}");
    }
    expect(DB::table('sessions')->count())->toBe(0);
});
