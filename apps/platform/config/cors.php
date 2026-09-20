<?php

declare(strict_types=1);

/*
 * Cross-origin access for browser clients of the API.
 *
 * The Guardian Console is served from the same origin as the API (ADR 0016), so
 * it does not use CORS and this allow-list ships empty. It exists for a future,
 * legitimate external browser consumer and is an explicit list from the
 * environment: never '*', and never widened pre-emptively. CORS is a browser
 * convenience, not a security boundary: authorization is always enforced
 * server-side.
 */

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 600,

    // Stays false (ADR 0016): no cross-origin request may carry the session cookie.
    'supports_credentials' => false,

];
