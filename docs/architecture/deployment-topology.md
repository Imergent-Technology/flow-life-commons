# Deployment topology

How the platform is intended to be served in production, and what the hosting account must therefore be able to do.

> **Status: requirements and assumptions, not verified facts about the production host.** The mechanism below has been exercised on Apache with PHP 8.3 in a representative container. The actual cPanel account has **not** been checked; the items under [Owner verification](#owner-verification) remain open. Nothing here is implemented or automated — deployment procedure is still a [deferred runbook](../runbooks/README.md).

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

**Consequence:** the Console and the platform stay separately built and separately tested, but become **one web-server deployment unit**. Releasing the Console means writing its build output into the platform's document root. The release procedure is not designed yet.

## Owner verification

Open items. None of them affect the architecture — only whether this hosting account can serve it as designed.

| # | To confirm | Where | If unavailable |
| --- | --- | --- | --- |
| 1 | `commons.flowlifeglobal.org` can have an **independent document root** (for example `/home/<user>/commons/platform/public`), not forced under `public_html`, and not shared with WordPress | cPanel → Domains → subdomain document root | The Console and API could not be isolated from the WordPress docroot; revisit before the epic ships |
| 2 | **`.htaccess` overrides are honoured** with `mod_rewrite` on that subdomain (`AllowOverride All` or equivalent) | Try a rewrite rule and observe | Routing must move into server config; the arrangement is unchanged but no longer self-contained |
| 3 | **PHP 8.3** selectable for the subdomain with `pdo_mysql`, `mbstring`, `openssl`, `intl`, `bcmath`, `zip`, `fileinfo`, `ctype`, `tokenizer` | cPanel → MultiPHP Manager, Select PHP Version → Extensions | The platform cannot run; a different host or PHP build is needed |
| 4 | **HTTPS is active** on the subdomain (AutoSSL or equivalent) | cPanel → SSL/TLS Status | **Hard blocker for the cookie design.** `__Host-` requires `Secure`, so the session cookie cannot be issued over plain HTTP |
| 5 | **`mod_headers` is enabled** | Set a header in `.htaccess` and read a response | The Console's static files ship with **no** security headers while the API keeps them. Treat as a blocker ([ADR 0026](../adr/0026-production-browser-security-policy.md)) |
| 6 | **Cron runs `schedule:run` every minute** | cPanel → Cron Jobs | Nothing prunes idle sessions; the table grows without limit (a capacity problem, not a security one) |
| 7 | **No web access to `.env`, `storage/`, `vendor/` or the repository** | Request each path over HTTPS | **`.env` holds the application key and the database password.** Hard blocker |

The full list, with what each failure costs, is in the [production readiness runbook](../runbooks/production-readiness.md).

Items 1, 4, 5 and 7 are load-bearing for security; the rest are correctness or operations.

## Security headers, and why they live in `.htaccess`

The Console's `index.html` and hashed assets are served **straight from the document root by Apache** and never reach PHP, so a browser security policy expressed only in Laravel middleware would cover the API and leave the Console's own document — the one an XSS would execute in — with none. `public/.htaccess` therefore carries the policy for the whole origin, generated from `apps/platform/config/security.php` by `php artisan security:headers --format=apache`, with a test failing if the two drift ([ADR 0026](../adr/0026-production-browser-security-policy.md)).

**This adds a hosting requirement: `mod_headers`.** Without it the static half of the origin ships with no security headers while the API keeps them, which is a materially weaker deployment; it is item 5 of the owner verification list above.

The routing rules this file will also need — the `/api` carve-out and the SPA fallback described above — are **still not in it**. They belong with the deployment procedure, which is not designed.

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
