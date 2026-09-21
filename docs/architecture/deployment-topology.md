# Deployment topology

How the platform is intended to be served in production, and what the hosting account must therefore be able to do.

> **Status: the host capabilities this topology needs were probed on the real account on 2026-09-21 and confirmed** ([Owner verification](#owner-verification) below). The *procedure* that produces the topology is decided — [ADR 0027](../adr/0027-release-and-deployment-model.md) and the [deployment runbook](../runbooks/deployment.md) — but **nothing on the host is automated**, and only the developer-side artifact build exists (`./flow release`), and the routing rules this page describes are still not in the committed `.htaccess`.

## The production host, measured

| | Measured 2026-09-21 |
| --- | --- |
| Web server | **Apache**, on **CloudLinux** |
| PHP handler | **LSAPI / `mod_lsapi`**, so `PHP_SAPI` reports `litespeed`. **Not LiteSpeed Web Server** — `/usr/local/apache` and `/opt/alt` exist, `/usr/local/lsws` does not, and MariaDB reports `cll-lve`. Do not infer an LSCache layer from that SAPI string |
| PHP | 8.3.33 for web requests, with every required extension |
| Database | `10.11.18-MariaDB-cll-lve`, matching the development engine |
| Edge | **Direct to origin**, no proxy, CDN or page cache. The WordPress apex is separately behind Sucuri/Cloudproxy — see [trust boundaries](trust-boundaries.md) |

## Same origin, by requirement

The Guardian Console and the Platform API are served from **one origin**:

```
https://commons.flowlifeglobal.org/          → Guardian Console (static Vite build)
https://commons.flowlifeglobal.org/api/v1/   → Platform API (Laravel)
https://commons.flowlifeglobal.org/up        → liveness probe (plain text, eleven bytes)
```

This is a **security requirement, not a convenience** ([ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md)). It is what allows the privileged session cookie to be host-only, so it is never transmitted to `flowlifeglobal.org`, where WordPress lives as a deliberately lower-trust component ([ADR 0004](../adr/0004-wordpress-adapter-not-authority.md)).

A cookie scoped to `.flowlifeglobal.org` would reach WordPress on every request. That arrangement is **rejected**, and the alternative of hosting the Console on a sibling subdomain is rejected with it.

WordPress keeps its own origin and its own trust level. Service-to-service calls from the WordPress companion are unaffected by any of this: they are not browser requests ([ADR 0018](../adr/0018-client-and-delegated-authentication.md)).

## Serving arrangement

One document root, which is Laravel's `public/` directory with the Console's build output placed alongside the front controller:

```
<docroot>/                 ← Laravel public/
  index.php                ← Laravel front controller
  index.html               ← Guardian Console shell (Vite build)
  assets/                  ← Console bundle
  .htaccess
```

Request routing, in order: `/api/*` and `/up` go to Laravel; any request matching a real file or directory is served as-is; everything else falls back to `index.html` so the Console can own its client-side routes.

Exercised on Apache 2.4 with PHP 8.3 against a live database, using Laravel's stock rules extended with the API carve-out and the SPA fallback:

| Request | Result |
| --- | --- |
| `GET /` | 200 `text/html` — Console shell |
| `GET /api/v1/health` | 200 `application/json` from Laravel |
| `GET /up` | 200 `text/plain` — `up` |
| `GET /people/123` | 200 — Console shell, SPA fallback rather than a 404 |
| `GET /assets/<hash>.css` | 200 `text/css`, served statically |
| `GET /api/v1/nope` | 404 `application/json` — Laravel's JSON 404, not Apache's HTML one |

`DirectoryIndex index.html index.php` is set explicitly so `/` resolves to the Console rather than the front controller. Laravel's stock rules already forward the `Authorization` and `X-XSRF-Token` headers, which the CSRF design depends on.

**Consequence:** the Console and the platform stay separately built and separately tested, but become **one web-server deployment unit**. Releasing the Console means writing its build output into the platform's document root — which is why the Console's production build is assembled into the artifact rather than shipped separately ([ADR 0027](../adr/0027-release-and-deployment-model.md)), so the two halves of the origin cannot drift.

In production the document root is not a release directory but `current/public`, where `current` is a symlink swapped atomically at each release. The serving arrangement above is unchanged by that indirection, and **the host was measured doing it**: the first request after an atomic swap served the new release for both PHP and static content, with no cache clear, restart or wait.

## Owner verification

**Probed on the real account on 2026-09-21. All of these passed.** None of them affected the architecture — only whether this hosting account could serve it as designed, and it can. The full record, including what was measured and the one item still open, is in the [production readiness runbook](../runbooks/production-readiness.md).

| # | Confirmed | Status |
| --- | --- | --- |
| 1 | An **independent document root** for the subdomain, not under `public_html`, not shared with WordPress, **and reached through a symlink** | Verified. Note the cPanel UI takes a *home-relative* path |
| 2 | **`.htaccess` overrides honoured**, with `mod_rewrite` | Verified, including a condition testing a file outside the document root through the storage symlink |
| 3 | **PHP 8.3** with every required extension | Verified — 8.3.33, none missing |
| 4 | **HTTPS is active** on the subdomain | Verified |
| 5 | **`mod_headers` is enabled** | Verified — a header set inside `<IfModule mod_headers.c>` reached the client, so the generated block will be effective |
| 6 | **Cron runs every minute** | Verified. The binary must be the absolute `/usr/local/bin/php`: bare `php` under cron is `cgi-fcgi`, not `cli` |
| 7 | **No web access to `.env`, `storage/`, `vendor/` or the repository** | Verified — every private path returned 403 or 404 |

Items 1, 4, 5 and 7 are load-bearing for security; the rest are correctness or operations.

**Still open:** outbound mail authentication, deliberately deferred pending an organizational decision about Flow Life's mail arrangement. It does not affect this topology.

## Security headers, and why they live in `.htaccess`

The Console's `index.html` and hashed assets are served **straight from the document root by Apache** and never reach PHP, so a browser security policy expressed only in Laravel middleware would cover the API and leave the Console's own document — the one an XSS would execute in — with none. `public/.htaccess` therefore carries the policy for the whole origin, generated from `apps/platform/config/security.php` by `php artisan security:headers --format=apache`, with a test failing if the two drift ([ADR 0026](../adr/0026-production-browser-security-policy.md)).

**This adds a hosting requirement: `mod_headers`.** Without it the static half of the origin ships with no security headers while the API keeps them, which is a materially weaker deployment; it is item 5 of the owner verification list above.

The routing rules this file will also need are **still not in it**: the `/api` and `/up` carve-out, the SPA fallback described above, a maintenance arm that reads Laravel's own `storage/framework/down` so the static half of the origin observes `artisan down`, and private-path defence in depth. All four are specified in [ADR 0027](../adr/0027-release-and-deployment-model.md) and listed in the [deployment runbook](../runbooks/deployment.md#9-not-built-yet); they are implemented in the release-tooling phase, together with the matching change to the production-equivalent development gateway, so the browser suite keeps proving the routing semantics Apache will serve.

Until then, **the SPA fallback does not work**: `/people/123` reaches Laravel and gets a JSON 404 rather than the Console shell.

## The scheduler: one cron entry

Production needs exactly one, and everything it runs is in source control (`routes/console.php`):

```
* * * * *   cd <app> && php artisan schedule:run >> /dev/null 2>&1
```

Per-task cron entries are deliberately avoided: they are invisible to review, drift between environments and are lost on a host migration. The same application schedule works on cPanel now and on a VPS later. Full contract and verification: [production readiness](../runbooks/production-readiness.md).

## Production constraints inherited from the foundation

Unchanged by this design ([charter](charter.md)): no Docker, Node.js, Redis or resident daemons in production. The Console ships as static files built in CI or on a developer machine; the queue is drained from the scheduler tick under cron; the database is MariaDB 10.11 with PostgreSQL portability preserved ([ADR 0005](../adr/0005-mariadb-with-postgresql-portability.md)).

## Development parity

Development serves the Console and API from a single origin, `commons.flowlife.localhost`, matching production: the gateway routes `/api/*` (and `/up`) to the platform and everything else to the Vite dev server ([docker](../development/docker.md)). Local HTTPS is not needed: browsers treat `*.localhost` as a secure context, so `Secure` and `__Host-` cookies work there over plain HTTP.

The gateway also serves a second, **production-equivalent** origin, `prod.flowlife.localhost`: the Console's real Vite **build** as static files beside the API, under the exact production security headers. It exists so the strict production policy is proved in a real browser against the real build rather than asserted as a string ([ADR 0026](../adr/0026-production-browser-security-policy.md)); `./flow test e2e` builds the Console before running those journeys. The only part of the production policy it cannot exercise is HSTS, which is HTTPS-only.
