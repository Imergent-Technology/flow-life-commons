<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;

/*
 * How the framework reads a request's path, base URL and host, pinned for G6-D26 (docs/audits/g6/wp6b-dependency-advisories.md).
 *
 * The SERVER VARIABLES below are the ones a real Apache gave PHP for each request form, measured by scripts/tests/apache-surface.sh
 * with the production .htaccess (SCRIPT_NAME /index.php; PATH_INFO only for a real "/index.php/..." path). They are what a
 * request looks like AFTER Apache; this file does not, and cannot, say how the production host's own Apache or LSAPI builds them.
 *
 * Symfony http-foundation 7.4.23 fixed two advisories about exactly this reading (GHSA-3h27-hjv7-fqjx: the script name matched
 * as a mere prefix of a path segment; GHSA-5mx8-mwrp-5693: a "//" URI turning the base URL into a host). The first test fails
 * on 7.4.19 (it was run there) and passes on 7.4.23.
 */

/** @param array<string, string> $extra */
function apacheShapedRequest(string $requestUri, array $extra = []): Request
{
    return new Request(server: array_replace([
        'HTTP_HOST' => 'commons.test',
        'SERVER_NAME' => 'commons.test',
        'SERVER_PORT' => '80',
        'REMOTE_ADDR' => '203.0.113.7',
        'DOCUMENT_ROOT' => '/srv/commons/current/public',
        'SCRIPT_FILENAME' => '/srv/commons/current/public/index.php',
        'SCRIPT_NAME' => '/index.php',
        'PHP_SELF' => '/index.php',
        'REQUEST_URI' => $requestUri,
        'REQUEST_METHOD' => 'GET',
    ], $extra));
}

it('does not read a script name that is only the start of a longer segment as the script name (GHSA-3h27-hjv7-fqjx)', function (string $uri) {
    $request = apacheShapedRequest($uri);

    // "/index.phpadmin" is a path of its own, not "/admin" behind a front controller: a rule enforced in front of PHP for
    // "/admin" must see the same path that routing sees.
    expect($request->getPathInfo())->toBe($uri)
        ->and($request->path())->toBe(ltrim($uri, '/'));
})->with(['/index.phpadmin', '/index.phpapi/v1/health', '/index.php.bak']);

it('still reads a real script name followed by a path as that path', function () {
    $viaScript = apacheShapedRequest('/index.php/api/v1/health', ['PATH_INFO' => '/api/v1/health', 'PHP_SELF' => '/index.php/api/v1/health']);

    expect($viaScript->getPathInfo())->toBe('/api/v1/health')
        ->and($viaScript->getBaseUrl())->toBe('/index.php')
        ->and(apacheShapedRequest('/api/v1/health')->getPathInfo())->toBe('/api/v1/health')
        ->and(apacheShapedRequest('/api/v1/health')->getBaseUrl())->toBe('');
});

it('cannot be steered to another host by a URI that starts with two slashes (GHSA-5mx8-mwrp-5693)', function (string $uri) {
    $request = apacheShapedRequest($uri);
    $urls = new UrlGenerator(app('router')->getRoutes(), $request);

    expect($request->getBaseUrl())->not->toStartWith('//')
        ->and($request->getBaseUrl())->not->toContain('example.test')
        ->and($request->getSchemeAndHttpHost())->toBe('http://commons.test')
        ->and($urls->to('/accept-invitation'))->toBe('http://commons.test/accept-invitation');
})->with(['//api/v1/health', '//example.test/api/v1/health', '/\\example.test/x', '//example.test/index.php/x']);

it('trusts no proxy, so no forwarded header from any address can name the host, scheme or client (CVE-2026-121619, CVE-2026-123339)', function (string $remote) {
    $request = apacheShapedRequest('/api/v1/health', [
        'REMOTE_ADDR' => $remote,
        'HTTP_X_FORWARDED_HOST' => 'evil.example',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
        'HTTP_X_FORWARDED_PORT' => '8443',
    ]);

    expect(Request::getTrustedProxies())->toBe([])
        ->and($request->getHost())->toBe('commons.test')
        ->and($request->getScheme())->toBe('http')
        ->and($request->getPort())->toBe(80)
        ->and($request->getClientIp())->toBe($remote);
})->with([
    'a public address' => '203.0.113.7',
    'loopback' => '127.0.0.1',
    'a private range' => '10.0.0.1',
    'an IPv4-mapped IPv6 address' => '::ffff:10.0.0.1',
    'a 6to4 address' => '2002:0a00:0001::1',
]);

it('trusts exactly the host of the application URL and none of its subdomains', function () {
    config(['app.url' => 'https://commons.example']);

    $hosts = (new TrustHosts(app()))->hosts();

    expect($hosts)->toBe(['commons.example']);
});
