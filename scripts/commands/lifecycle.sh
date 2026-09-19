#!/usr/bin/env bash
# ./flow up | down | restart | status | logs
# shellcheck shell=bash

cmd_up() {
    local profiles=()
    while (($# > 0)); do
        case "$1" in
            --profile)
                [[ -n "${2:-}" ]] || die "up: --profile needs a name (postgres, redis)"
                profiles+=(--profile "$2")
                shift
                ;;
            *) die "up: unknown option '$1'" ;;
        esac
        shift
    done

    require_docker
    require_setup

    step "Starting the development stack"
    dc "${profiles[@]}" up -d --wait --wait-timeout 180

    echo
    print_urls
}

cmd_down() {
    local volumes=0 assume_yes=0
    while (($# > 0)); do
        case "$1" in
            -v | --volumes) volumes=1 ;;
            -y | --yes) assume_yes=1 ;;
            *) die "down: unknown option '$1'" ;;
        esac
        shift
    done

    require_docker

    if ((volumes == 1)); then
        warn "--volumes deletes the MariaDB and PostgreSQL data volumes (all local database contents)."
        if ((assume_yes == 0)); then
            confirm "Delete local database volumes?" || die "aborted"
        fi
        dc --profile '*' down --remove-orphans --volumes
    else
        dc --profile '*' down --remove-orphans
    fi
}

cmd_restart() {
    require_docker
    require_setup
    dc restart "$@"
}

cmd_status() {
    require_docker
    dc --profile '*' ps
    echo
    local port
    port="$(gateway_port)"
    probe "Guardian Console" "http://guardian.flowlife.localhost:$port/"
    probe "API health      " "http://api.flowlife.localhost:$port/api/v1/health"
    probe "Mailpit         " "http://mail.flowlife.localhost:$port/"
}

cmd_logs() {
    local follow=(-f)
    if [[ "${1:-}" == "--no-follow" ]]; then
        follow=()
        shift
    fi
    require_docker
    dc --profile '*' logs --tail=100 "${follow[@]}" "$@"
}

# --- helpers ----------------------------------------------------------------

print_urls() {
    local port
    port="$(gateway_port)"
    info "  Guardian Console  http://guardian.flowlife.localhost:$port"
    info "  API health        http://api.flowlife.localhost:$port/api/v1/health"
    info "  Mailpit           http://mail.flowlife.localhost:$port"
    info "  MariaDB           127.0.0.1:$(root_env FLOW_DB_PORT 13306)  (database flowlife, user flowlife)"
    info "  (*.localhost resolves in browsers and curl; no /etc/hosts entry needed)"
}

# probe LABEL URL: curl resolves *.localhost to loopback itself.
probe() {
    local label="$1" url="$2" code
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$url" 2>/dev/null || true)"
    if [[ "$code" =~ ^[23] ]]; then
        ok "$label $url ($code)"
    else
        bad "$label $url (${code:-no response})"
    fi
}
