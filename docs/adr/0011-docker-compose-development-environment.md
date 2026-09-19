# ADR 0011: Docker Compose development environment and `./flow`

- **Status:** Accepted
- **Date:** 2026-09-19
- **Supersedes:** none
- **Superseded by:** none
- **Amended by:** [ADR 0016](0016-guardian-console-same-origin-session-authentication.md) (development moves to a single origin during the Identity epic)

## Context

Developers work on WSL2 (with Docker Desktop's WSL integration), native Linux and Arch Linux, and some will have other dev stacks running on the same machine. Host prerequisites should be minimal, the environment should be reproducible, and it must not create root-owned files in the repo. Production shares none of this (cPanel).

## Decision

- **Docker Compose** provides the development environment. Host prerequisites: Git, Docker with Compose v2, Bash, and an editor. PHP, Composer, Node, databases and tooling run in containers.
- **WSL2 uses Docker Desktop with WSL integration** (the documented primary path); native Docker Engine on Linux or Arch follows the same workflow. The repository must live on the Linux filesystem, not `/mnt/c`.
- Services: `gateway` (Caddy), `platform` (php-fpm), `queue`, `scheduler`, `mariadb` (10.11), `guardian` (Vite dev server), `mailpit`. Optional profiles: `postgres`, `redis`, `e2e`. WordPress is left as a future profile.
- **Routing** uses `*.flowlife.localhost` names through the gateway (`api.`, `guardian.`, `mail.`); they resolve to loopback in browsers and curl without editing hosts files.
- **Non-standard host ports** (standard port with a leading `1`: 18080, 13306, 15432, 16379), bound to `127.0.0.1`, so the stack runs beside other dev environments. All are overridable in the gitignored `.env`.
- **File ownership:** containers run as the developer's UID/GID (`FLOW_UID`/`FLOW_GID`, written by `./flow setup`); no user is baked into images.
- **Vite HMR uses native filesystem events**; polling is an opt-in fallback (`FLOW_VITE_POLLING=true`).
- **`./flow`** is a thin Bash orchestrator (dispatcher plus `scripts/commands/*.sh`) over Compose and in-container tooling. It contains no application logic, and CI runs the same commands.

## Consequences

- A new developer runs `./flow setup` then `./flow up`.
- Development is richer than production (Redis-capable, Mailpit, workers); rules keep the application from depending on that.
- Docker Desktop on WSL relies on its bind-mount and port-forwarding behaviour; `./flow doctor` checks the common pitfalls.
- The dev image is not the production artifact. A release process is designed later.
- One extra moving part (Caddy) buys production-like host routing and a single published port.

## Alternatives considered

- **Laravel Sail / DDEV / Lando:** capable, but bring their own opinions and abstractions; plain Compose is more transparent and easier to keep aligned with CI.
- **Host-installed PHP/Node:** faster to start, drifts between machines and OSes.
- **`php artisan serve` and host ports for each service:** simpler, but conflicts with other stacks and lacks host-based routing and CORS realism.
- **Makefile instead of `./flow`:** workable, but Bash gives better argument handling and preflight checks; `make` is not universal on Arch minimal installs.
