#!/usr/bin/env bash
# ./flow db shell | fresh
# shellcheck shell=bash

cmd_db() {
    local sub="${1:-}"
    shift || true
    case "$sub" in
        shell) db_shell "$@" ;;
        fresh) db_fresh "$@" ;;
        *) die "usage: ./flow db shell [--pgsql] | ./flow db fresh [-y]" ;;
    esac
}

db_shell() {
    require_docker
    if [[ "${1:-}" == "--pgsql" ]]; then
        ensure_postgres
        dcq --profile postgres exec "${TTY_ARGS[@]}" postgres psql -U flowlife flowlife
    else
        ensure_mariadb
        # Deliberate single quotes: the password expands inside the container.
        # shellcheck disable=SC2016
        dcq exec "${TTY_ARGS[@]}" mariadb sh -c 'exec mariadb -uflowlife -p"$MARIADB_PASSWORD" flowlife'
    fi
}

# Destructive: drops every table in the development database and re-migrates.
db_fresh() {
    local assume_yes=0
    case "${1:-}" in
        -y | --yes) assume_yes=1 ;;
        "") ;;
        *) die "db fresh: unknown option '$1'" ;;
    esac

    require_docker
    require_setup

    local app_env
    app_env="$(platform_env APP_ENV local)"
    [[ "$app_env" == "local" ]] || die "db fresh refuses to run when APP_ENV=$app_env (only 'local')."

    if ((assume_yes == 0)); then
        warn "This DROPS ALL TABLES in the development database 'flowlife' and re-runs migrations."
        confirm "Continue?" || die "aborted"
    fi

    ensure_mariadb
    php_run php artisan migrate:fresh --force --no-interaction
}
