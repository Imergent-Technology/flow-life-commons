<?php

declare(strict_types=1);

/*
 * Cross-origin access for browser clients of the API (Guardian Console, later
 * others). Origins are an explicit allow-list from the environment; never '*'.
 * CORS is a browser convenience, not a security boundary: authorization is
 * always enforced server-side.
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

    // Revisit with the Identity/Access design (cookie vs bearer authentication).
    'supports_credentials' => false,

];
