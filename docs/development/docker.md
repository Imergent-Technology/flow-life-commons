# Docker environment

Development runs in Docker Compose ([ADR 0011](../adr/0011-docker-compose-development-environment.md)); `compose.yaml` is at the repo root and images/config live in `infrastructure/docker/`. **Production does not use Docker** (shared cPanel hosting).

## Services

| Service | Image | Purpose | Published on host |
| --- | --- | --- | --- |
| `gateway` | `caddy:2-alpine` | Reverse proxy; host-based routing | `127.0.0.1:18080` |
| `platform` | built from `infrastructure/docker/php` (PHP 8.3 php-fpm) | Laravel app; also the Composer/Artisan tool container | none |
| `queue` | same PHP image | `queue:listen` (development worker) | none |
| `scheduler` | same PHP image | `schedule:work` (development scheduler) | none |
| `mariadb` | `mariadb:10.11` | Database (UTC, utf8mb4) | `127.0.0.1:13306` |
| `guardian` | `node:24-bookworm-slim` | Vite dev server (also the npm tool container) | none (via gateway) |
| `mailpit` | `axllent/mailpit` (pinned) | Catches dev mail; SMTP `mailpit:1025` internally | none (via gateway) |

The PHP image includes `bcmath intl pcntl pdo_mysql pdo_pgsql zip`, the extensions the platform needs on cPanel PHP 8.3, plus `pdo_pgsql` for the portability run.

### Profiles (optional)

| Profile | Service | Notes |
| --- | --- | --- |
| `postgres` | `postgres:16-alpine` on `127.0.0.1:15432` | Used by `./flow test backend --pgsql` and `./flow db shell --pgsql`; started automatically when needed |
| `redis` | `redis:7-alpine` on `127.0.0.1:16379` | Optional and **not consumed by the application**; `./flow up --profile redis` |
| `e2e` | Playwright image (`v1.63.0-noble`) | Used by `./flow test e2e`; keep the tag in step with `@playwright/test` |

**WordPress** is intentionally not in the stack yet. It will arrive as a `wordpress` profile when the first real feature needs it ([WordPress integration](../integrations/wordpress.md)).

## Ports and routing

Every published host port is deliberately uncommon (standard port with a leading `1`) so this stack coexists with other dev environments, and bound to loopback. Override any of them in the gitignored `.env`:

| Variable | Default |
| --- | --- |
| `FLOW_GATEWAY_PORT` | `18080` |
| `FLOW_DB_PORT` | `13306` |
| `FLOW_PG_PORT` | `15432` |
| `FLOW_REDIS_PORT` | `16379` |
| `FLOW_BIND_ADDRESS` | `127.0.0.1` |

Routing uses **`*.flowlife.localhost`** names, which resolve to loopback in browsers and curl with no hosts-file edits. The gateway listens on the *same port inside and outside the container*, so URLs and Vite's HMR websocket agree everywhere, and the Playwright container can share the gateway's network namespace. Requests for any other host get a 404.

| Host | Routes to |
| --- | --- |
| `commons.flowlife.localhost` `/api`, `/api/*`, `/up` | Laravel (`platform`, php-fpm) |
| `commons.flowlife.localhost` everything else | Guardian Console (Vite dev server, including HMR websockets) |
| `mail.flowlife.localhost` | Mailpit UI |

### Single origin

The Guardian Console and the API are served from **one origin**, as in production, so the session cookie can be host-only ([ADR 0016](../adr/0016-guardian-console-same-origin-session-authentication.md)). Authentication is therefore developed against the production model from the start. Consequences:

- **Console routes must never start with `/api` or be `/up`**: those belong to Laravel. An unknown `/api/...` path answers with a JSON 404 from Laravel, never the Console's HTML.
- The Console calls the API at the relative path `/api/v1/...`. There is no API base URL to configure.
- **No CORS is involved** in Console → API traffic. `CORS_ALLOWED_ORIGINS` in `apps/platform/.env` ships empty and `supports_credentials` stays `false`; add an origin only for a legitimate external browser consumer, never with credentials.
- **Mailpit stays on its own host** on purpose: it renders arbitrary mail HTML, so it must not share an origin with the authenticated Console. It is also a dev-only tool with no production counterpart.
- Local HTTPS is **not** needed: browsers treat `*.localhost` as a secure context, so `Secure` and `__Host-` cookies work over plain HTTP there. This is **measured, not assumed** (see [Session cookies locally](#session-cookies-locally)).
- The former `guardian.` and `api.` hosts are retired and answer 404. If you set up before this change, update `apps/platform/.env` (`APP_URL`, empty `CORS_ALLOWED_ORIGINS`); `./flow doctor` warns when they are stale. If you change the gateway port, update `APP_URL` too.
- Container-to-container traffic is unaffected: services still reach each other by service name (`platform:9000`, `mariadb`, `mailpit`); only the browser-facing hostnames changed.

### Session cookies locally

Development uses the **exact production session cookie**: `__Host-flowlife-session`, `Secure`, `HttpOnly`, `Path=/`, no `Domain`, `SameSite=Lax`. Nothing is different locally, and no cookie setting can be overridden from the environment (see `config/session.php`).

Whether a browser would accept that on plain HTTP was checked in the real Playwright Chromium (153) against `http://commons.flowlife.localhost:18080`: it stored the cookie (host-only, `Secure`, `HttpOnly`, `Lax`) and returned it on the next request. `./flow test e2e` re-proves this on every run (`e2e/auth.spec.ts`). **Only Chromium has been verified.** If a different browser refuses a `Secure` cookie on plain-HTTP `*.localhost`, sign-in will appear to succeed and then every request will look anonymous. Use Chromium/Chrome/Edge, or say so and a development-only arrangement will be designed then; the production cookie will not be weakened to suit a development browser.

### Signing in locally

There is no administrator bootstrap yet, so no account exists in a fresh development database. `./flow test e2e` seeds one **development-only** fixture Account (`e2e.guardian@example.org`, password in `apps/platform/database/seeders/E2eAccountSeeder.php`). The account holds the `guardian` role (so it has the `console.access` capability). The seeder refuses to run outside the `local` and `testing` environments and is not part of `DatabaseSeeder`. The Guardian Console has no login screen yet; the API is exercised by the e2e tests and the feature tests.

Sessions are stored in the `sessions` table and expire after 30 minutes of request inactivity or 12 hours from sign-in, whichever comes first. Requests through the gateway all reach Laravel from the gateway's address, so the per-address login limit is shared in development; `./flow test e2e` clears the cache first so earlier runs cannot trip it.

## File ownership

Containers run as your UID/GID, written to `.env` by `./flow setup` (`FLOW_UID`, `FLOW_GID`), so files created by Composer, npm, Artisan and Vite are yours, not root's. There is no user baked into any image. Never run `./flow` or Docker with `sudo`.

## Environment files

| File | Purpose | Committed |
| --- | --- | --- |
| `.env` | Compose settings: UID/GID, host ports | no |
| `apps/platform/.env` | Laravel settings (dev placeholders, `APP_KEY`) | no |
| `.env.example`, `apps/platform/.env.example`, `apps/guardian-console/.env.example` | Templates with safe placeholders | yes |

Development credentials (`flowlife` / `flowlife_dev_only`) are throwaway and exist only in `compose.yaml` and the `.example` files. See [secrets](../security/secrets.md).

## HMR and polling

Vite watches with **native filesystem events**; on WSL2 with the repo on the Linux filesystem this works through Docker Desktop's bind mounts, and edits appear in the browser in about a second without a reload. The HMR websocket is proxied through the gateway (`hmr.clientPort` is the gateway port).

If updates do not arrive in your environment (for example a repo on a network or Windows-mounted drive), set `FLOW_VITE_POLLING=true` in `.env` and `./flow restart guardian`. Polling costs CPU; it is a fallback, not the default.

## Volumes and reset

Named volumes `mariadb-data` and `postgres-data` hold database contents. `./flow down` keeps them; `./flow down --volumes` deletes them after confirmation (`-y` skips it). `node_modules` and `vendor` live in the bind-mounted app folders so your IDE can read them.

## Queue and scheduler

The dev `queue` container reloads code per job (`queue:listen`); the `scheduler` container runs `schedule:work`. Production will not have resident workers ([ADR 0010](../adr/0010-database-queue-redis-ready.md)).

## CI parity

CI uses the same `compose.yaml` and `./flow` commands. The only CI-specific step is pre-building the PHP image with layer caching (`docker/bake-action`), then `./flow setup --skip-build`.

## Not covered yet

Production images and any deployment are not part of this environment. The release artifact is built by `./flow release` ([deployment runbook](../runbooks/deployment.md#8-flow-release-the-developer-side-tooling)); backup, restore and deployment stay runbook actions ([runbooks](../runbooks/README.md)).
