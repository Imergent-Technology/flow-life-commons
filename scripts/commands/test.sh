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
    # The journey must be deterministic and self-contained: it never reaches a public service. The
    # breached-password check is the one outbound dependency, so the platform must be running the no-op
    # checker (development and CI: .env.example sets it). Refuse rather than quietly call the live one.
    local driver
    driver="$(php_run php artisan tinker --execute='echo config("identity.password.compromised_check.driver");' 2>/dev/null | tail -n1 | tr -d '[:space:]')"
    [[ "$driver" == none ]] || die "e2e must not depend on the public breached-password service, but IDENTITY_COMPROMISED_PASSWORD_CHECK resolves to '${driver:-unset}'. Set IDENTITY_COMPROMISED_PASSWORD_CHECK=none in apps/platform/.env (see .env.example) and retry."
    # The journeys sign in many times from one address; the platform's per-address login limit (30 per 15
    # minutes by default) would refuse them. Development and CI raise it (.env.example); production keeps the default.
    local attempts
    attempts="$(php_run php artisan tinker --execute='echo config("identity.login_throttle.max_attempts_per_ip");' 2>/dev/null | tail -n1 | tr -d '[:space:]')"
    if ! [[ "$attempts" =~ ^[0-9]+$ ]] || ((attempts < 100)); then
        die "e2e signs in many times from one address, but the platform allows only '${attempts:-unset}' attempts per address per window. Set IDENTITY_LOGIN_MAX_ATTEMPTS_PER_IP=200 in apps/platform/.env (see .env.example) and retry."
    fi
    # The same journeys present second-factor codes from that one address, against a separate per-address limit (also
    # 30 per 15 minutes by default) that a full run comes within one attempt of. Raised the same way.
    local codes
    codes="$(php_run php artisan tinker --execute='echo config("identity.credential_throttle.mfa_challenge.per_ip");' 2>/dev/null | tail -n1 | tr -d '[:space:]')"
    if ! [[ "$codes" =~ ^[0-9]+$ ]] || ((codes < 100)); then
        die "e2e presents many second-factor codes from one address, but the platform allows only '${codes:-unset}' per address per window. Set IDENTITY_MFA_MAX_PER_IP=200 in apps/platform/.env (see .env.example) and retry."
    fi
    # The browser security journeys (e2e/security.spec.ts) run against the gateway's
    # production-equivalent site, which serves the Console's real production BUILD under the production
    # security headers. Building it here is what makes that surface the thing being tested rather than
    # whatever happened to be in dist/ from an earlier day.
    step "Building the Guardian Console (the production-equivalent origin serves this build)"
    node_run npm run build
    step "Migrating the development database (a newer schema than the stack was built with would fail the seed)"
    php_run php artisan migrate --force --no-interaction
    step "Seeding the e2e fixture account (development only)"
    # A known active Account to sign in as, and a cleared cache so login throttle counters
    # left by earlier runs cannot make this one flaky. Both are development data.
    php_run php artisan db:seed --class=E2eAccountSeeder --force --no-interaction
    # Legitimately authenticated sessions for the journeys that are not about signing in (development only). They are minted
    # by the platform's own sign-in, in-process, so no journey spends the public login rate budget just to get started. The
    # file holds live (if worthless) session cookies: it is git-ignored, and rewritten on every run.
    php_run php artisan db:seed --class=E2eSessionSeeder --force --no-interaction
    mkdir -p apps/guardian-console/e2e/.fixtures
    cp apps/platform/storage/app/private/e2e-sessions.json apps/guardian-console/e2e/.fixtures/sessions.json
    php_run php artisan cache:clear --no-interaction
    step "E2E tests (Playwright, chromium)"
    local status=0
    dcq --profile e2e run --rm --no-deps "${TTY_ARGS[@]}" e2e npx playwright test --grep-invert @maintenance "$@" || status=$?

    # The maintenance journeys raise the authoritative `storage/framework/down` flag, and that flag is
    # global to the origin: every other journey running beside them would be answered with a 503. So
    # they run alone, in their own pass, with one worker.
    step "E2E maintenance journeys (serial: these take the whole origin down)"
    dcq --profile e2e run --rm --no-deps "${TTY_ARGS[@]}" e2e npx playwright test --grep @maintenance --workers=1 "$@" || status=$?

    # Unconditionally, through the real authority: a crashed or interrupted pass must never leave the
    # development origin in maintenance mode for the next person.
    php_run php artisan up --no-interaction >/dev/null 2>&1 || true

    return "$status"
}
