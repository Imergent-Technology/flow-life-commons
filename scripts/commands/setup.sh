#!/usr/bin/env bash
# ./flow setup: idempotent first-time (and repair) setup. Safe to re-run.
# shellcheck shell=bash

cmd_setup() {
    local skip_build=0
    while (($# > 0)); do
        case "$1" in
            --skip-build) skip_build=1 ;; # CI pre-builds images with layer caching
            *) die "setup: unknown option '$1'" ;;
        esac
        shift
    done

    require_docker

    if is_wsl && [[ "$FLOW_ROOT" == /mnt/* ]]; then
        warn "This repository is on the Windows filesystem ($FLOW_ROOT)."
        warn "Move it into your WSL home (e.g. ~/development) or file watching, permissions and speed will suffer."
    fi
    if [[ "$(id -u)" == "0" ]]; then
        warn "Running as root: files created in the repository will be owned by root."
    fi

    step "Writing local environment files"
    setup_root_env
    setup_platform_env

    if ((skip_build == 0)); then
        step "Building the PHP development image"
        dc build platform
    fi

    step "Pulling service images"
    # queue and scheduler reuse the locally built PHP image, so they are not pulled.
    dc pull --quiet gateway mariadb guardian mailpit

    step "Installing PHP dependencies (composer install)"
    php_run composer install --no-interaction --prefer-dist

    step "Installing frontend dependencies (npm ci)"
    node_run npm ci --no-fund --no-audit

    step "Starting MariaDB"
    ensure_mariadb
    ensure_mariadb_test_db

    if [[ -z "$(platform_env APP_KEY)" ]]; then
        step "Generating APP_KEY"
        php_run php artisan key:generate --no-interaction
    fi

    step "Running database migrations"
    php_run php artisan migrate --force --no-interaction

    echo
    ok "Setup complete. Next: ./flow up"
    info "  Guardian Console  http://commons.flowlife.localhost:$(gateway_port)"
    info "  API health        http://commons.flowlife.localhost:$(gateway_port)/api/v1/health  (same origin as the Console)"
    info "  Mailpit           http://mail.flowlife.localhost:$(gateway_port)"
}

setup_root_env() {
    local env_file="$FLOW_ROOT/.env" uid gid
    uid="$(id -u)"
    gid="$(id -g)"

    if [[ ! -f "$env_file" ]]; then
        sed -e "s/^FLOW_UID=.*/FLOW_UID=$uid/" -e "s/^FLOW_GID=.*/FLOW_GID=$gid/" \
            "$FLOW_ROOT/.env.example" >"$env_file"
        ok "created .env (containers will run as uid:gid $uid:$gid)"
        return
    fi

    if [[ "$(root_env FLOW_UID)" != "$uid" || "$(root_env FLOW_GID)" != "$gid" ]]; then
        warn ".env has FLOW_UID/FLOW_GID $(root_env FLOW_UID)/$(root_env FLOW_GID) but you are $uid/$gid; edit .env if files end up with the wrong owner."
    else
        ok ".env already present"
    fi
}

setup_platform_env() {
    local env_file="$FLOW_ROOT/apps/platform/.env"
    if [[ ! -f "$env_file" ]]; then
        cp "$FLOW_ROOT/apps/platform/.env.example" "$env_file"
        ok "created apps/platform/.env"
    else
        ok "apps/platform/.env already present"
    fi
}
