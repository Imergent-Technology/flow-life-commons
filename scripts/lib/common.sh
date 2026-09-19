#!/usr/bin/env bash
# Shared helpers for ./flow and scripts/commands/*. Sourced, never executed.
# shellcheck shell=bash

: "${FLOW_ROOT:?common.sh must be sourced by ./flow}"

if [[ -t 1 ]]; then
    C_RED=$'\033[31m' C_GREEN=$'\033[32m' C_YELLOW=$'\033[33m' C_BLUE=$'\033[34m' C_RESET=$'\033[0m'
else
    C_RED='' C_GREEN='' C_YELLOW='' C_BLUE='' C_RESET=''
fi

step() { printf '%s==>%s %s\n' "$C_BLUE" "$C_RESET" "$*"; }
info() { printf '%s\n' "$*"; }
ok() { printf '%s✓%s %s\n' "$C_GREEN" "$C_RESET" "$*"; }
warn() { printf '%s!%s %s\n' "$C_YELLOW" "$C_RESET" "$*" >&2; }
bad() { printf '%s✗%s %s\n' "$C_RED" "$C_RESET" "$*" >&2; }
die() {
    printf 'flow: %s\n' "$*" >&2
    exit 1
}

# confirm PROMPT: ask y/N on the terminal; fails (declines) when not interactive.
confirm() {
    local reply=''
    [[ -t 0 ]] || return 1
    read -r -p "$1 [y/N] " reply
    [[ "$reply" =~ ^[Yy]$ ]]
}

# Interactive commands need a TTY; CI and pipes must not ask for one.
TTY_ARGS=()
if [[ ! -t 0 || ! -t 1 ]]; then
    TTY_ARGS=(-T)
fi

# --- Docker Compose ---------------------------------------------------------

dc() {
    docker compose --project-directory "$FLOW_ROOT" -f "$FLOW_ROOT/compose.yaml" "$@"
}

# Like dc, without container create/start noise (for run/exec wrappers).
dcq() {
    dc --progress quiet "$@"
}

require_docker() {
    command -v docker >/dev/null 2>&1 ||
        die "docker not found. Install Docker (WSL2: Docker Desktop with WSL integration enabled for this distro)."
    docker compose version >/dev/null 2>&1 ||
        die "the 'docker compose' plugin is missing (Compose v2+ is required)."
    docker info >/dev/null 2>&1 ||
        die "cannot reach the Docker daemon. WSL2: start Docker Desktop and enable WSL integration for this distro. Linux: start dockerd and add your user to the 'docker' group."
}

# --- Environment files ------------------------------------------------------

# env_get FILE KEY [DEFAULT]: read KEY from a dotenv-style file without sourcing it.
env_get() {
    local file="$1" key="$2" default="${3:-}" line=''
    if [[ -f "$file" ]]; then
        line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"
    fi
    if [[ -n "$line" ]]; then
        line="${line#*=}"
        line="${line%\"}"
        line="${line#\"}"
        printf '%s' "$line"
    else
        printf '%s' "$default"
    fi
}

root_env() { env_get "$FLOW_ROOT/.env" "$1" "${2:-}"; }
platform_env() { env_get "$FLOW_ROOT/apps/platform/.env" "$1" "${2:-}"; }

gateway_port() { root_env FLOW_GATEWAY_PORT 18080; }

is_wsl() { grep -qi microsoft /proc/version 2>/dev/null; }

require_setup() {
    local missing=()
    [[ -f "$FLOW_ROOT/.env" ]] || missing+=(".env")
    [[ -f "$FLOW_ROOT/apps/platform/.env" ]] || missing+=("apps/platform/.env")
    [[ -f "$FLOW_ROOT/apps/platform/vendor/autoload.php" ]] || missing+=("apps/platform/vendor (composer install)")
    [[ -d "$FLOW_ROOT/apps/guardian-console/node_modules" ]] || missing+=("apps/guardian-console/node_modules (npm ci)")
    if ((${#missing[@]} > 0)); then
        die "environment is not set up (missing: ${missing[*]}). Run ./flow setup first."
    fi
}

# --- Tool containers --------------------------------------------------------

# php_run CMD...: run in a throwaway platform container (no dependencies started).
php_run() { dcq run --rm --no-deps "${TTY_ARGS[@]}" platform "$@"; }

# node_run CMD...: run in a throwaway Guardian Console (Node) container.
node_run() { dcq run --rm --no-deps "${TTY_ARGS[@]}" guardian "$@"; }

service_running() {
    dc --profile '*' ps --status running --services 2>/dev/null | grep -Fxq "$1"
}

# --- Databases --------------------------------------------------------------

ensure_mariadb() {
    dcq up -d --wait mariadb >/dev/null
}

ensure_postgres() {
    dcq --profile postgres up -d --wait postgres >/dev/null
}

# The test databases are separate from the development database so that
# RefreshDatabase can never touch development data (see tests/TestCase.php).
ensure_mariadb_test_db() {
    # The single quotes are deliberate: $MARIADB_ROOT_PASSWORD expands inside the
    # container, so the password never appears on this host's command line.
    # shellcheck disable=SC2016
    dcq exec -T mariadb sh -c 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"' >/dev/null <<'SQL'
CREATE DATABASE IF NOT EXISTS flowlife_test;
GRANT ALL PRIVILEGES ON flowlife_test.* TO 'flowlife'@'%';
SQL
}

ensure_postgres_test_db() {
    local exists
    exists="$(dcq --profile postgres exec -T postgres psql -U flowlife -d flowlife -tAc \
        "SELECT 1 FROM pg_database WHERE datname='flowlife_test'")"
    if [[ "$exists" != "1" ]]; then
        dcq --profile postgres exec -T postgres createdb -U flowlife flowlife_test
    fi
}
