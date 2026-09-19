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

Routing uses **`*.flowlife.localhost`** names, which resolve to loopback in browsers and curl with no hosts-file edits: `api.`, `guardian.`, `mail.`. The gateway listens on the *same port inside and outside the container*, so URLs, CORS origins and Vite's HMR websocket agree everywhere, and the Playwright container can share the gateway's network namespace. Requests for any other host get a 404.

The API allows cross-origin calls from the Guardian origin via `CORS_ALLOWED_ORIGINS` in `apps/platform/.env` (an explicit allow-list, never `*`). If you change the gateway port, update that value and `APP_URL`.

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

Production images, release packaging, backup/restore and any deployment are deliberately deferred until designed (see [runbooks](../runbooks/README.md)).
