<?php

declare(strict_types=1);

/*
 * Application API, served under /api/v1 (prefix and `api` middleware are applied
 * by bootstrap/app.php).
 *
 * Each module owns its endpoints in app/Modules/<Module>/Http/routes.php; this
 * file only loads them so modules never edit a central route list. Every route
 * added here must also appear in openapi/openapi.yaml (enforced by a test).
 */

foreach (glob(app_path('Modules/*/Http/routes.php')) ?: [] as $moduleRoutes) {
    require $moduleRoutes;
}
