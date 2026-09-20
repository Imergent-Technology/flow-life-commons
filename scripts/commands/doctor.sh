#!/usr/bin/env bash
# ./flow doctor: diagnose the host environment. Read-only; changes nothing.
# shellcheck shell=bash

DOCTOR_FAILS=0
DOCTOR_WARNS=0

doctor_ok() { ok "$1"; }
doctor_warn() {
    warn "$1"
    DOCTOR_WARNS=$((DOCTOR_WARNS + 1))
}
doctor_fail() {
    bad "$1"
    DOCTOR_FAILS=$((DOCTOR_FAILS + 1))
}

cmd_doctor() {
    step "Host"
    doctor_bash
    doctor_location
    doctor_user

    step "Docker"
    doctor_docker

    # The remaining checks need a working Docker CLI/daemon.
    if docker info >/dev/null 2>&1; then
        step "Project"
        doctor_project
        step "Ports"
        doctor_ports
    fi

    step "Local domains"
    doctor_domains

    echo
    if ((DOCTOR_FAILS > 0)); then
        bad "$DOCTOR_FAILS problem(s), $DOCTOR_WARNS warning(s)."
        return 1
    fi
    ok "No problems found ($DOCTOR_WARNS warning(s))."
}

doctor_bash() {
    if ((BASH_VERSINFO[0] > 4 || (BASH_VERSINFO[0] == 4 && BASH_VERSINFO[1] >= 4))); then
        doctor_ok "bash $BASH_VERSION"
    else
        doctor_fail "bash $BASH_VERSION is too old (need 4.4+)"
    fi
}

doctor_location() {
    if is_wsl; then
        if [[ "$FLOW_ROOT" == /mnt/* ]]; then
            doctor_fail "WSL: repository is on the Windows filesystem ($FLOW_ROOT). Move it into the Linux filesystem (e.g. ~/development); file watching (HMR) and performance depend on it."
        else
            doctor_ok "WSL: repository is on the Linux filesystem ($FLOW_ROOT)"
        fi
    else
        doctor_ok "repository at $FLOW_ROOT"
    fi

    local free_kb
    free_kb="$(df -Pk "$FLOW_ROOT" | awk 'NR==2 {print $4}')"
    if ((free_kb < 5 * 1024 * 1024)); then
        doctor_warn "less than 5 GB free on this filesystem (images and volumes need space)"
    fi
}

doctor_user() {
    if [[ "$(id -u)" == "0" ]]; then
        doctor_warn "running as root: files created in the repo will be root-owned. Use a normal user."
        return
    fi
    doctor_ok "running as uid:gid $(id -u):$(id -g)"

    if [[ -f "$FLOW_ROOT/.env" ]]; then
        if [[ "$(root_env FLOW_UID)" == "$(id -u)" && "$(root_env FLOW_GID)" == "$(id -g)" ]]; then
            doctor_ok ".env FLOW_UID/FLOW_GID match your user"
        else
            doctor_warn ".env FLOW_UID/FLOW_GID ($(root_env FLOW_UID)/$(root_env FLOW_GID)) differ from your user; files may get the wrong owner"
        fi
    fi
}

doctor_docker() {
    if ! command -v docker >/dev/null 2>&1; then
        if is_wsl; then
            doctor_fail "docker not found in this WSL distro. Install Docker Desktop and enable Settings > Resources > WSL Integration for this distro."
        else
            doctor_fail "docker not found. Install Docker Engine (or Docker Desktop) and the compose plugin."
        fi
        return
    fi
    doctor_ok "$(docker --version)"

    if docker compose version >/dev/null 2>&1; then
        doctor_ok "$(docker compose version)"
    else
        doctor_fail "the 'docker compose' plugin (v2+) is missing"
        return
    fi

    if ! docker info >/dev/null 2>&1; then
        if is_wsl; then
            doctor_fail "cannot reach the Docker daemon. Start Docker Desktop and enable WSL integration for this distro."
        else
            doctor_fail "cannot reach the Docker daemon. Start dockerd; ensure your user is in the 'docker' group (re-login after adding)."
        fi
        return
    fi

    local os
    os="$(docker info --format '{{.OperatingSystem}}' 2>/dev/null || true)"
    if [[ "$os" == *"Docker Desktop"* ]]; then
        doctor_ok "Docker Desktop daemon reachable (WSL integration is working)"
    else
        doctor_ok "Docker daemon reachable ($os)"
    fi
}

doctor_project() {
    if [[ -f "$FLOW_ROOT/.env" ]]; then doctor_ok ".env present"; else doctor_warn ".env missing: run ./flow setup"; fi
    if [[ -f "$FLOW_ROOT/apps/platform/.env" ]]; then
        if [[ -n "$(platform_env APP_KEY)" ]]; then
            doctor_ok "apps/platform/.env present with APP_KEY"
        else
            doctor_warn "apps/platform/.env has no APP_KEY: run ./flow setup"
        fi
    else
        doctor_warn "apps/platform/.env missing: run ./flow setup"
    fi
    if [[ -f "$FLOW_ROOT/apps/platform/vendor/autoload.php" ]]; then
        doctor_ok "composer dependencies installed"
    else
        doctor_warn "composer dependencies missing: run ./flow setup"
    fi
    if [[ -d "$FLOW_ROOT/apps/guardian-console/node_modules" ]]; then
        doctor_ok "npm dependencies installed"
    else
        doctor_warn "npm dependencies missing: run ./flow setup"
    fi
    if docker image inspect flowlife-dev/php:8.3 >/dev/null 2>&1; then
        doctor_ok "PHP development image built"
    else
        doctor_warn "PHP development image not built: run ./flow setup"
    fi
    doctor_topology
    doctor_sessions
}

# Sessions must live in the database (ADR 0016, ADR 0010) with the frozen 30-minute inactivity
# timeout. A platform .env written before authentication existed still says SESSION_DRIVER=file.
doctor_sessions() {
    [[ -f "$FLOW_ROOT/apps/platform/.env" ]] || return 0
    local driver lifetime
    driver="$(platform_env SESSION_DRIVER database)"
    lifetime="$(platform_env SESSION_LIFETIME 30)"

    if [[ "$driver" == database ]]; then
        doctor_ok "SESSION_DRIVER is database"
    else
        doctor_warn "apps/platform/.env SESSION_DRIVER is '$driver'; sessions must be database-backed (set SESSION_DRIVER=database)"
    fi
    if [[ "$lifetime" != 30 ]]; then
        doctor_warn "apps/platform/.env SESSION_LIFETIME is '$lifetime'; the frozen inactivity timeout is 30 (minutes)"
    fi
}

# The Console and API share one origin (ADR 0016). A platform .env written before that
# still names the retired hosts; URL generation and CORS would quietly disagree with the gateway.
doctor_topology() {
    [[ -f "$FLOW_ROOT/apps/platform/.env" ]] || return 0
    local expected app_url cors
    expected="http://commons.flowlife.localhost:$(gateway_port)"
    app_url="$(platform_env APP_URL)"
    cors="$(platform_env CORS_ALLOWED_ORIGINS)"

    if [[ "$app_url" == "$expected" ]]; then
        doctor_ok "APP_URL matches the single-origin gateway ($expected)"
    else
        doctor_warn "apps/platform/.env APP_URL is '${app_url:-unset}'; set it to $expected"
    fi
    if [[ -n "$cors" ]]; then
        doctor_warn "apps/platform/.env CORS_ALLOWED_ORIGINS is '$cors'; the Console is same-origin and needs none (leave it empty unless an external browser client requires it)"
    fi
}

port_in_use() {
    (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null
}

# doctor_port LABEL PORT [SERVICE]: free, or in use by our own running service.
doctor_port() {
    local label="$1" port="$2" service="${3:-}"
    if [[ -n "$service" ]] && service_running "$service"; then
        doctor_ok "$label port $port (in use by this stack)"
    elif port_in_use "$port"; then
        doctor_fail "$label port $port is already in use by something else; change it in .env"
    else
        doctor_ok "$label port $port is free"
    fi
}

doctor_ports() {
    doctor_port "gateway" "$(gateway_port)" gateway
    doctor_port "MariaDB" "$(root_env FLOW_DB_PORT 13306)" mariadb
    doctor_port "PostgreSQL (optional profile)" "$(root_env FLOW_PG_PORT 15432)" postgres
    doctor_port "Redis (optional profile)" "$(root_env FLOW_REDIS_PORT 16379)" redis
}

doctor_domains() {
    if getent hosts commons.flowlife.localhost >/dev/null 2>&1; then
        doctor_ok "*.localhost resolves via the system resolver"
        return
    fi
    # Not an error: browsers and curl (>= 7.78) resolve *.localhost to loopback themselves.
    doctor_ok "*.localhost is not in the system resolver (normal); browsers and curl resolve it to 127.0.0.1"
    if is_wsl; then
        info "    WSL: open the URLs in your Windows browser; Chrome, Edge and Firefox resolve *.localhost too."
    fi
}
