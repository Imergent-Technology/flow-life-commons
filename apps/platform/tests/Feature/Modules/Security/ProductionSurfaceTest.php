<?php

declare(strict_types=1);

/*
 * The composed production public surface: `public/.htaccess` and `public/maintenance.php`
 * (ADR 0027, deployment runbook section 4; ADR 0026 for the policy block).
 *
 * This file pins STRUCTURE — which rule classes exist, in which order, and which mechanisms are
 * forbidden. It deliberately does not try to understand Apache: a test that re-implements
 * mod_rewrite proves only that two implementations agree. BEHAVIOUR is proved against a live origin
 * in apps/guardian-console/e2e/production-surface.spec.ts.
 *
 * Order is the thing worth pinning, because every way this file fails is an ordering mistake that
 * still "works" for the request class someone happened to try:
 *
 *   - maintenance before the private denials  -> /.env answers 503 during an outage instead of 403
 *   - the SPA fallback before the API carve-out -> /api/v1/nope answers 200 with the Console shell
 *   - the SPA fallback before the denials     -> a private path answers 200 with the Console shell
 */

/** @return array{0: string, 1: string} the two files of the production public surface */
function productionSurface(): array
{
    return [
        (string) file_get_contents(base_path('public/.htaccess')),
        (string) file_get_contents(base_path('public/maintenance.php')),
    ];
}

/**
 * The .htaccess DIRECTIVES, with commentary removed.
 *
 * Absence is asserted against this rather than the raw file: the comments deliberately name the
 * mechanisms that must not be used, and explain why, so a raw search finds the very words it is
 * looking for and the test passes or fails for the wrong reason.
 */
function apacheDirectives(): string
{
    [$htaccess] = productionSurface();

    return implode("\n", array_filter(
        explode("\n", $htaccess),
        static fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));
}

/** The responder's CODE, with its comments removed, for the same reason. */
function responderCode(): string
{
    return php_strip_whitespace(base_path('public/maintenance.php'));
}

/** The offset of the first line matching a pattern, for asserting rule order. */
function ruleAt(string $htaccess, string $pattern): int
{
    expect(preg_match($pattern, $htaccess, $_, PREG_OFFSET_CAPTURE))->toBe(1, "no rule matching $pattern");

    preg_match($pattern, $htaccess, $matches, PREG_OFFSET_CAPTURE);

    return (int) $matches[0][1];
}

describe('the composed .htaccess', function () {
    it('carries every rule class the production origin needs', function () {
        [$htaccess] = productionSurface();

        expect($htaccess)
            // The Console's shell, not the front controller, answers `/`.
            ->toContain('DirectoryIndex index.html index.php')
            // No directory listing of the document root, and no content negotiation.
            ->toContain('Options -Indexes')
            ->toContain('Options -MultiViews')
            // The policy block (ADR 0026); its exact text is pinned in BrowserSecurityPolicyTest.
            ->toContain('<IfModule mod_headers.c>')
            ->toContain('Content-Security-Policy')
            // The maintenance arm reads Laravel's own flag through the shared storage symlink.
            ->toContain('%{DOCUMENT_ROOT}/../storage/framework/down -f')
            ->toContain('/maintenance.php [L]')
            // The API and the liveness probe reach Laravel's front controller.
            ->toContain('index.php [L]')
            // The Console's client-side routes.
            ->toContain('/index.html [L]');
    });

    it('denies private paths, dot-directories included, and keeps the ACME path reachable', function () {
        [$htaccess] = productionSurface();

        // A <FilesMatch "^\."> would miss /.git/config, whose file name has no dot. The rewrite matches
        // any dotted path SEGMENT, and exempts .well-known or certificate renewal on the host breaks.
        expect($htaccess)->toContain('(^|/)\.(?!well-known/)')
            ->and($htaccess)->toMatch('/RewriteRule.+vendor.+\[F,L\]/')
            ->and($htaccess)->toMatch('/RewriteRule.+release\\\\?\.json.+\[F,L\]/');

        foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'storage', 'vendor'] as $directory) {
            expect($htaccess)->toMatch('/RewriteRule "\^\(.*\b'.$directory.'\b.*\)\(\/\|\$\)" - \[F,L\]/');
        }
    });

    it('never intercepts the API or the liveness probe with the maintenance responder', function () {
        [$htaccess] = productionSurface();

        // Laravel answers these itself, negotiating content, so a JSON caller gets a JSON 503. The
        // exclusion covers a bare `/api` as well as `/api/...`: the development gateway routes both to
        // Laravel, and a static HTML 503 delivered to an API client is worse than the outage.
        expect($htaccess)->toContain('RewriteCond %{REQUEST_URI} !^/(api($|/)|up$|maintenance\.php$)');
    });

    it('orders the rules so that no later one can answer for an earlier one', function () {
        [$htaccess] = productionSurface();

        $denials = ruleAt($htaccess, '/RewriteRule "\(\^\|\/\)\\\\\.\(\?\!well-known\/\)"/');
        $maintenance = ruleAt($htaccess, '/RewriteCond %\{DOCUMENT_ROOT\}\/\.\.\/storage\/framework\/down -f/');
        $api = ruleAt($htaccess, '/RewriteRule "\^\(api\(\$\|\/\)\|up\$\)" index\.php \[L\]/');
        $fallback = ruleAt($htaccess, '/RewriteRule \^ \/index\.html \[L\]/');

        expect($denials)->toBeLessThan($maintenance, 'private paths must be denied before the maintenance arm can answer for them')
            ->and($maintenance)->toBeLessThan($fallback, 'maintenance must be decided before the Console shell is served')
            ->and($api)->toBeLessThan($fallback, 'the API carve-out must precede the SPA fallback, or an unknown API path becomes the Console shell');
    });

    it('serves the Console shell only for what is neither a file nor a directory', function () {
        [$htaccess] = productionSurface();

        // Without both conditions the fallback swallows the hashed assets and favicon.ico.
        expect($htaccess)->toMatch('/RewriteCond %\{REQUEST_FILENAME\} !-f\s+RewriteCond %\{REQUEST_FILENAME\} !-d\s+RewriteRule \^ \/index\.html \[L\]/');
    });

    it('does not reintroduce the maintenance mechanism that failed on this host', function () {
        // ErrorDocument 503 paired with R=503 was tried on the production account and returned the
        // server's own bare body instead of the page (ADR 0027). The responder replaced it.
        $directives = apacheDirectives();

        expect($directives)->not->toContain('ErrorDocument')
            ->and($directives)->not->toContain('R=503')
            ->and($directives)->not->toContain('maintenance.html');
    });

    it('adds no proxy, CDN or cache-purge behaviour', function () {
        // Commons is direct to origin, and deliberately trusts no reverse proxy (trust boundaries).
        $directives = apacheDirectives();

        foreach (['X-Forwarded', 'RemoteIPHeader', 'LSCache', 'Sucuri', 'mod_pagespeed'] as $absent) {
            expect($directives)->not->toContain($absent);
        }
    });
});

describe('the maintenance responder', function () {
    it('loads nothing: not Laravel, not vendor, not the environment', function () {
        // The situation it exists for includes a release that is broken, half-installed or mid-swap.
        // Anything it requires is something that can stop it answering at the worst moment.
        $responder = responderCode();

        expect($responder)->not->toMatch('/\b(require|require_once|include|include_once)\b/')
            ->and($responder)->not->toContain('vendor/autoload')
            ->and($responder)->not->toContain('bootstrap/app')
            ->and($responder)->not->toContain('$_ENV')
            ->and($responder)->not->toContain('getenv')
            ->and($responder)->not->toContain('Illuminate');
    });

    it('sets the status and the three headers the runbook pins', function () {
        [, $responder] = productionSurface();

        expect($responder)->toContain('http_response_code(503)')
            ->and($responder)->toContain("header('Content-Type: text/html; charset=utf-8')")
            ->and($responder)->toContain("header('Retry-After: 120')")
            ->and($responder)->toContain("header('Cache-Control: no-store, no-cache, must-revalidate')");
    });

    it('emits a self-contained page that needs no asset the maintenance rule would block', function () {
        [, $responder] = productionSurface();

        expect($responder)->toContain('<h1>Down for maintenance</h1>');

        // No external anything: the rewrite intercepts assets too, so a stylesheet, script, image or
        // font referenced here would be answered with this same page. Inline CSS is not the answer
        // either — the origin's policy is `style-src 'self'` with no 'unsafe-inline' (ADR 0026), so an
        // inline block would simply be refused by the browser. The markup carries no styling at all.
        expect(responderCode())->not->toMatch('/<(script|style|link|img)\b/')
            ->and(responderCode())->not->toContain('http://')
            ->and(responderCode())->not->toContain('https://');
    });

    it('produces exactly that page when executed', function () {
        // Structure is not behaviour: the body is proved here, and the status and headers are proved
        // over HTTP in e2e/production-surface.spec.ts, where a browser can see them.
        $output = shell_exec(escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('public/maintenance.php')).' 2>&1');

        expect($output)->toContain('<h1>Down for maintenance</h1>')
            ->and($output)->toContain('<!doctype html>')
            ->and($output)->not->toContain('Fatal error')
            ->and($output)->not->toContain('Warning');
    });
});
