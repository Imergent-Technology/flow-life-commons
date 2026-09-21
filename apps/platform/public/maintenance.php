<?php

declare(strict_types=1);

/*
 * The maintenance responder for the STATIC half of the production origin
 * (ADR 0027, "Maintenance model"; deployment runbook section 4).
 *
 * Apache hands a request here by INTERNAL rewrite ([L], never R=503) when the authoritative flag
 * `storage/framework/down` exists — the file `php artisan down` writes and `php artisan up` removes.
 * There is no second flag and no state of its own: this file is a renderer, and it is reached only
 * because the one authority said so.
 *
 * It loads NOTHING. No Laravel, no `vendor/`, no `.env`, no configuration, no database. That is the
 * entire point: the situation this file exists for includes a release that is broken, half-installed
 * or mid-swap, and a responder that needs the application to work cannot answer when the application
 * does not. Everything it sends is a literal below.
 *
 * Laravel keeps `/api/*` and `/up`, which Apache never rewrites here: its own maintenance handling
 * negotiates content, so an API client gets a JSON 503 rather than this HTML page.
 *
 * NO CSS, deliberately. The origin's Content-Security-Policy is `style-src 'self'` with no
 * 'unsafe-inline' (ADR 0026), so an inline <style> block would be refused by the browser, and an
 * external stylesheet is unreachable because the maintenance rewrite intercepts assets too. The
 * markup below is therefore written to read correctly with no styling at all, which is also what it
 * will do in a text browser, a screen reader and a curl transcript. The security headers themselves
 * are added by the web server to every response from this document root, including this one, so they
 * are deliberately not repeated here.
 */

http_response_code(503);

// The three headers the deployment runbook pins. `Retry-After` is advisory to clients and crawlers;
// `no-store` matters more than it looks — a cached 503 outlives the maintenance window.
header('Content-Type: text/html; charset=utf-8');
header('Retry-After: 120');
header('Cache-Control: no-store, no-cache, must-revalidate');

echo <<<'HTML'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Down for maintenance</title>
</head>
<body>
<h1>Down for maintenance</h1>
<p>The Flow Life Commons console is briefly unavailable while an update is applied.</p>
<p>This usually takes less than a minute. Please try again shortly.</p>
</body>
</html>

HTML;
