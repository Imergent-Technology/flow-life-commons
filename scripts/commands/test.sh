#!/usr/bin/env bash
# ./flow test [backend|frontend|e2e] [--pgsql] [runner args...]
# shellcheck shell=bash

cmd_test() {
    local target=all pgsql=0 extra=()

    if [[ "${1:-}" =~ ^(backend|frontend|e2e|all)$ ]]; then
        target="$1"
        shift
    fi
    while (($# > 0)); do
        case "$1" in
            --pgsql) pgsql=1 ;;
            --)
                shift
                extra+=("$@")
                break
                ;;
            *) extra+=("$1") ;; # forwarded to Pest / Vitest / Playwright
        esac
        shift
    done

    if ((pgsql == 1)) && [[ "$target" != backend && "$target" != all ]]; then
        die "test: --pgsql only applies to the backend suite"
    fi

    require_docker
    require_setup

    case "$target" in
        backend) test_backend "$pgsql" "${extra[@]}" ;;
        frontend) test_frontend "${extra[@]}" ;;
        e2e) test_e2e "${extra[@]}" ;;
        all)
            test_backend "$pgsql" "${extra[@]}"
            test_frontend "${extra[@]}"
            ;;
    esac
}

# test_backend PGSQL [pest args...]
test_backend() {
    local pgsql="$1"
    shift

    if [[ "$pgsql" == 1 ]]; then
        step "Backend tests (Pest) on PostgreSQL"
        ensure_postgres
        ensure_postgres_test_db
        # Real environment variables beat both .env and phpunit.xml, switching engine only.
        dcq --profile postgres run --rm --no-deps "${TTY_ARGS[@]}" \
            -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
            -e DB_USERNAME=flowlife -e DB_PASSWORD=flowlife_dev_only \
            platform vendor/bin/pest "$@"
    else
        step "Backend tests (Pest) on MariaDB"
        ensure_mariadb
        ensure_mariadb_test_db
        php_run vendor/bin/pest "$@"
    fi
}

test_frontend() {
    step "Frontend tests (Vitest)"
    node_run npm test -- "$@"
}

# Browser smoke test against the running stack, from the `e2e` profile.
test_e2e() {
    local s
    for s in gateway platform guardian mariadb; do
        service_running "$s" || die "e2e needs the running stack ($s is not up). Run ./flow up first."
    done
    step "Seeding the e2e fixture account (development only)"
    # A known active Account to sign in as, and a cleared cache so login throttle counters
    # left by earlier runs cannot make this one flaky. Both are development data.
    php_run php artisan db:seed --class=E2eAccountSeeder --force --no-interaction
    php_run php artisan cache:clear --no-interaction
    step "E2E tests (Playwright, chromium)"
    dcq --profile e2e run --rm --no-deps "${TTY_ARGS[@]}" e2e npx playwright test "$@"
}
