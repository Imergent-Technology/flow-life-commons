#!/usr/bin/env bash
# ./flow shell | artisan | composer | npm: run tooling inside containers.
# shellcheck shell=bash

# exec_or_run SERVICE CMD...: reuse the running container, else start a throwaway one.
exec_or_run() {
    local service="$1"
    shift
    if service_running "$service"; then
        dcq exec "${TTY_ARGS[@]}" "$service" "$@"
    else
        dcq run --rm --no-deps "${TTY_ARGS[@]}" "$service" "$@"
    fi
}

cmd_shell() {
    local service="${1:-platform}" sh_bin=sh
    require_docker
    case "$service" in
        platform | queue | scheduler | guardian | mariadb) sh_bin=bash ;;
    esac
    exec_or_run "$service" "$sh_bin"
}

cmd_artisan() {
    require_docker
    require_setup
    ensure_mariadb # most artisan commands touch the database
    exec_or_run platform php artisan "$@"
}

cmd_composer() {
    require_docker
    dcq run --rm --no-deps "${TTY_ARGS[@]}" platform composer "$@"
}

cmd_npm() {
    require_docker
    dcq run --rm --no-deps "${TTY_ARGS[@]}" guardian npm "$@"
}
