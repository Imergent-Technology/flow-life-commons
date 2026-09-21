# Getting started

```bash
git clone <repo-url> flow-life-commons
cd flow-life-commons
./flow setup     # first time only (about 1-2 minutes)
./flow up
```

Then open **http://commons.flowlife.localhost:18080**. It should show the Guardian Console shell with "API ok".

## Prerequisites

Only these on the host:

- Git
- Docker with Compose v2 (`docker compose`)
- Bash 4.4+
- An editor/IDE

PHP, Composer, Node/npm, MariaDB and all other tooling run in containers. Run `./flow doctor` at any time to diagnose the host.

### WSL2 (primary Windows path)

1. Install **Docker Desktop** and enable **Settings > Resources > WSL Integration** for your distro. (Docker Desktop's WSL integration is the supported route; running your own Docker Engine inside WSL also works and follows the Linux notes.)
2. **Keep the repository on the Linux filesystem**, e.g. `~/development/flow-life-commons`, **not** under `/mnt/c`. Cross-filesystem mounts are slow, break file-change events (HMR) and mangle permissions. `./flow doctor` fails if you are under `/mnt/`.
3. Open the URLs in your Windows browser. Chrome, Edge and Firefox resolve `*.localhost` to loopback themselves and Docker Desktop forwards the published ports.

### Native Linux and Arch Linux

Same workflow. Install Docker Engine and the Compose plugin, start the daemon, and add yourself to the `docker` group (log out and in afterwards):

```bash
# Arch
sudo pacman -S docker docker-compose git
sudo systemctl enable --now docker.service
sudo usermod -aG docker "$USER"
```

Ubuntu/Debian users install Docker from Docker's own apt repository (the distro `docker.io` package lacks the v2 Compose plugin on some releases).

## What `./flow setup` does

Idempotent and safe to re-run. It creates `.env` (with your UID/GID) and `apps/platform/.env` from their `.example` files, builds the PHP image, pulls the other images, runs `composer install` and `npm ci` in containers, starts MariaDB and creates the test databases, generates `APP_KEY`, and runs migrations.

## Creating an administrator

A fresh development database has no accounts. `identity:create-administrator` creates the first administrator and prints a one-time invitation token (see the [runbook](../runbooks/administrator-bootstrap.md)). To use it: open the Console's `/accept-invitation` page, paste the token and choose a password, then sign in. An administrator can use the Console only with a second factor ([ADR 0023](../adr/0023-multi-factor-authentication.md)), so your first sign-in walks you through setting up an authenticator app and saving ten recovery codes (shown once). Mailpit shows the password-recovery email if you need it. `./flow test e2e` seeds separate development-only fixture accounts (some already enrolled with a known authenticator secret) instead.

## Local URLs

| URL | What |
| --- | --- |
| http://commons.flowlife.localhost:18080 | Guardian Console (Vite dev server with HMR) |
| http://commons.flowlife.localhost:18080/api/v1/health | Platform API health endpoint: **same origin** as the Console |
| http://mail.flowlife.localhost:18080 | Mailpit (captured dev mail) |
| 127.0.0.1:13306 | MariaDB (database `flowlife`, user `flowlife`) for GUI clients |

## Everyday commands

```bash
./flow up / down / restart / status / logs [service]
./flow shell                # bash in the platform container
./flow artisan migrate      # any php artisan command
./flow composer require ... # composer in the platform container
./flow npm install ...      # npm in the Guardian Console
./flow test                 # backend + frontend tests
./flow check                # everything CI runs (add --pgsql for PostgreSQL)
./flow db shell             # SQL shell
./flow db fresh             # drop everything and re-migrate (local only, asks first)
./flow build                # production build of the Guardian Console
./flow release build ...    # build a release artifact from a tag (developer-side; see the deployment runbook)
./flow ci run               # trigger CI on GitHub; then ./flow ci watch
```

`./flow ci ...` is the only group needing the [GitHub CLI](https://cli.github.com) (`sudo apt install gh` then `gh auth login -s workflow`); everything else works without it. See [testing](testing.md#triggering-and-reviewing-ci).

`./flow` works from any directory inside the repo. See [Docker environment](docker.md), [testing](testing.md), [coding standards](coding-standards.md) and [Git workflow](git-workflow.md).

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| `cannot reach the Docker daemon` | WSL: start Docker Desktop and enable WSL integration. Linux: start dockerd, check `docker` group membership. |
| `port ... is already in use` | Change `FLOW_GATEWAY_PORT` / `FLOW_DB_PORT` in `.env` (defaults are deliberately uncommon), then `./flow up`. URLs use the new port. |
| Files owned by root | You ran Docker/`./flow` as root, or `FLOW_UID`/`FLOW_GID` in `.env` are wrong. Fix `.env`, then `sudo chown -R "$USER": apps`. |
| HMR does not update | See [HMR and polling](docker.md#hmr-and-polling). |
| Browser cannot resolve `*.localhost` | Use a current Chrome/Edge/Firefox. On Linux, `curl` also works. Otherwise add the names to your hosts file. |
| Start over completely | `./flow down --volumes`, then `./flow setup` (this deletes local database contents). |
