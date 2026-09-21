<?php

declare(strict_types=1);

use App\Modules\Security\Application\BrowserSecurityPolicy;
use App\Modules\Security\Http\ApplyBrowserSecurityHeaders;
use App\Modules\Security\Infrastructure\Console\ShowSecurityHeadersCommand;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Testing\PendingCommand;
use Tests\Support\Console;
use Tests\Support\Identity;
use Tests\Support\Mfa;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;

/*
 * The browser security policy (ADR 0026).
 *
 * The production origin is served by TWO things — Apache for the Console's files, Laravel for the API —
 * so the risk this file exists for is not "the header is wrong" but "the two halves disagree, and only
 * one of them was ever looked at". Every rule below is about the policy being single-sourced.
 *
 * What is NOT proved here: that the policy is survivable in a browser. A CSP that breaks the Console
 * passes every string assertion in this file. That is e2e/security.spec.ts, against the real build.
 */

/**
 * `artisan()` returns PendingCommand|int; every call here needs the object.
 *
 * @param  array<array-key, mixed>  $arguments
 */
function commandBrowserSecurityPolicy(string $name, array $arguments = []): PendingCommand
{
    $pending = artisan($name, $arguments);
    assert($pending instanceof PendingCommand);

    return $pending;
}

$directives = [
    "default-src 'none'",
    "script-src 'self'",
    "style-src 'self'",
    "img-src 'self'",
    "connect-src 'self'",
    "form-action 'self'",
    "base-uri 'none'",
    "frame-ancestors 'none'",
];

it('states the production content security policy exactly', function () use ($directives) {
    expect(config('security.csp'))->toBe($directives);
});

it('allows neither unsafe-inline nor unsafe-eval anywhere in the production policy', function () {
    // The production build needs neither, measured from dist/. Vite's DEV server does, and the
    // development gateway has its own weaker policy; this is the rule that stops the two merging.
    $policy = app(BrowserSecurityPolicy::class)->headers(secure: true);

    expect($policy['Content-Security-Policy'])->not->toContain('unsafe-inline')
        ->and($policy['Content-Security-Policy'])->not->toContain('unsafe-eval')
        ->and($policy['Content-Security-Policy'])->not->toContain('*')
        ->and($policy['Content-Security-Policy'])->not->toContain('http:');
});

it('puts the policy on every response, including errors and the liveness probe', function () {
    foreach (['/up', '/api/v1/health', '/api/v1/no-such-endpoint'] as $path) {
        $response = getJson($path);
        expect($response->headers->get('Content-Security-Policy'))->toBe(app(BrowserSecurityPolicy::class)->headers(secure: false)['Content-Security-Policy'], $path)
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff', $path)
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY', $path);
    }
});

it('adds itself to the global middleware stack without displacing the framework\'s own', function () {
    // Laravel's `use()` REPLACES the global stack rather than adding to it, and doing so here would have
    // quietly removed CORS handling, trusted hosts, maintenance mode and the input sanitisers — a
    // security change disguised as adding a header. The policy is prepended instead, and this is what
    // says so, because nothing else about the application would look different if it were not.
    $global = (fn (): array => $this->middleware)->call(app(Kernel::class));

    expect($global)->toContain(ApplyBrowserSecurityHeaders::class)
        ->and($global)->toContain(HandleCors::class)
        ->and($global)->toContain(TrustHosts::class)
        ->and($global)->toContain(ValidatePostSize::class)
        ->and(array_search(ApplyBrowserSecurityHeaders::class, $global, true))->toBe(0, 'the policy must be outermost, so it dresses every response including error pages');
});

it('keeps an authenticated API response out of every cache', function () {
    [$console] = Mfa::signedIn();

    expect($console->me()->headers->get('Cache-Control'))->toContain('no-store');
});

it('sends HSTS only over HTTPS, and never claims authority over sibling hosts', function () {
    // Flow Life runs other hosts under the same apex, WordPress among them, whose HTTPS this
    // application does not control. `includeSubDomains` from here would break them; `preload` would
    // make that irreversible.
    $policy = app(BrowserSecurityPolicy::class);

    expect($policy->headers(secure: false))->not->toHaveKey('Strict-Transport-Security')
        ->and($policy->headers(secure: true)['Strict-Transport-Security'])->toBe('max-age=31536000')
        ->and(config('security.hsts'))->not->toContain('includeSubDomains')
        ->and(config('security.hsts'))->not->toContain('preload');
});

it('does not send headers that are obsolete or actively harmful', function () {
    $sent = array_keys(app(BrowserSecurityPolicy::class)->headers(secure: true));

    // X-XSS-Protection enables a filter no current browser has and which introduced holes of its own;
    // the others are superseded. CSP is what does this job.
    expect($sent)->not->toContain('X-XSS-Protection')
        ->and($sent)->not->toContain('Expect-CT')
        ->and($sent)->not->toContain('Feature-Policy');
});

describe('one policy, two deployment adapters', function () {
    it('keeps production .htaccess identical to what the policy generates', function () {
        // In production Apache serves the Console's index.html and assets directly, so this file is the
        // only thing that covers them. A policy change that is not carried here would ship a Console
        // with weaker headers than its own API.
        $expected = ShowSecurityHeadersCommand::block(app(BrowserSecurityPolicy::class), 'apache');

        expect(file_get_contents(base_path('public/.htaccess')))->toContain($expected);
    });

    // The DEVELOPMENT gateway's copy of the same block is not checked here: the platform container
    // mounts apps/platform only, so infrastructure/ is not visible to this suite. It is checked where
    // the check is stronger anyway — e2e/security.spec.ts reads the headers off the live gateway and
    // compares them with the policy, which catches a Caddyfile that is right on disk and wrong in
    // effect (a misplaced `header` directive, a handle that never runs) as well as one that drifted.

    it('refuses a format it cannot generate rather than emitting nothing', function () {
        commandBrowserSecurityPolicy('security:headers', ['--format' => 'nginx'])->assertFailed();
    });
});

it('leaves the Console able to talk to its own API and nothing else', function () {
    // connect-src 'self' is only sufficient because the Console is same-origin (ADR 0016). A CORS
    // allow-list would mean a browser client somewhere needs to reach the API cross-origin, which this
    // policy would then be wrong about.
    $account = Identity::savedActiveAccount();
    $console = new Console;
    $console->login($account->email->value, Identity::PASSWORD)->assertOk();

    expect(config('cors.supports_credentials'))->toBeFalse()
        ->and(app(BrowserSecurityPolicy::class)->headers(secure: false)['Content-Security-Policy'])->toContain("connect-src 'self'");
});
