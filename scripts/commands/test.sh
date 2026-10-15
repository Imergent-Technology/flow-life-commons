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

# Keeps the minted browser sessions from idling out during a long run (development only; see E2eSessionKeepAlive).
e2e_keep_sessions_alive() {
    while sleep 300; do
        php_run php artisan db:seed --class=E2eSessionKeepAlive --force --no-interaction >/dev/null 2>&1 || true
    done
}

e2e_stop_keep_alive() {
    if [[ -n "${E2E_KEEPALIVE_PID:-}" ]]; then
        pkill -P "$E2E_KEEPALIVE_PID" 2>/dev/null || true # its pending `sleep`, which would otherwise outlive it
        kill "$E2E_KEEPALIVE_PID" 2>/dev/null || true
        E2E_KEEPALIVE_PID=''
    fi
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
    # Every browser journey except the development gateway's (e2e/gateway.spec.ts, which needs Vite) runs against the Console's real
    # production BUILD, served beside the real API under the production security headers by the gateway's production-equivalent site
    # (prod.flowlife.localhost). Building it here is what makes that surface the thing being tested rather than whatever happened to be
    # in dist/ from an earlier day. The directory's CONTENTS are removed first (never the directory: the gateway mounts it), so a build
    # that fails, or writes nothing, cannot leave an earlier one behind to be tested in its place; the build and its verification must
    # both succeed or the run stops here; and e2e/globalSetup.ts then refuses a build that is missing, incomplete or older than its
    # source, or an origin that is serving a different one. See docs/development/testing.md.
    step "Building the Guardian Console (the browser journeys run against this build, served by the production-equivalent origin)"
    find apps/guardian-console/dist -mindepth 1 -delete 2>/dev/null || true
    node_run npm run build
    node_run npm run verify:build
    step "Migrating the development database (a newer schema than the stack was built with would fail the seed)"
    php_run php artisan migrate --force --no-interaction
    step "Seeding the e2e fixture account (development only)"
    # A known active Account to sign in as, and a cleared cache so login throttle counters
    # left by earlier runs cannot make this one flaky. Both are development data.
    php_run php artisan db:seed --class=E2eAccountSeeder --force --no-interaction
    # Legitimately authenticated sessions for the journeys that are not about signing in (development only). They are minted
    # by the platform's own sign-in, in-process, so no journey spends the public login rate budget just to get started. The
    # file holds live (if worthless) session cookies: it is git-ignored, and rewritten on every run.
    #
    # E2E_AGED_SESSIONS=1 mints every one of them as if its last proof were already 20 minutes old (past the 15-minute window), the
    # diagnostic for a journey that leans on a MINTED session's recent proof: such a journey passes only if it happens to run early,
    # so how fast the host is decides the result. In this mode it fails at its first protected step, whatever the host's speed.
    local session_seeder=E2eSessionSeeder
    if [[ "${E2E_AGED_SESSIONS:-}" == 1 ]]; then
        session_seeder=E2eAgedSessionSeeder
        warn "E2E_AGED_SESSIONS=1: every minted session's proof is 20 minutes old. This run is a diagnostic, not the acceptance run."
    fi
    php_run php artisan db:seed --class="$session_seeder" --force --no-interaction
    step "Seeding the CRM demo data (development only, repeatable: it adds nothing that is already there)"
    php_run php artisan db:seed --class=CrmDemoSeeder --force --no-interaction
    step "Seeding the Discussions demo data (development only, repeatable, and repaired after the fixture Accounts are recreated)"
    php_run php artisan db:seed --class=DiscussionsDemoSeeder --force --no-interaction
    step "Seeding the Resources demo data (development only, repeatable: Packs that exist are left alone, and provenance is repaired)"
    php_run php artisan db:seed --class=ResourcesDemoSeeder --force --no-interaction
    step "Seeding the Relationships demo data (development only, repeatable: relationships that exist are left alone)"
    php_run php artisan db:seed --class=RelationshipsDemoSeeder --force --no-interaction
    mkdir -p apps/guardian-console/e2e/.fixtures
    cp apps/platform/storage/app/private/e2e-sessions.json apps/guardian-console/e2e/.fixtures/sessions.json
    php_run php artisan cache:clear --no-interaction
    step "E2E tests (Playwright, chromium)"
    local status=0
    # A minted session is first used whenever its journey is scheduled, and the platform ends a session after 30 minutes without a
    # request. A run that outlasts that (a slow host) would find Sign-in pages where those sessions were, so the sessions are kept
    # from idling out for as long as the run lasts. See E2eSessionKeepAlive: it moves `last_activity` and nothing else.
    e2e_keep_sessions_alive &
    E2E_KEEPALIVE_PID=$!
    trap e2e_stop_keep_alive EXIT
    dcq --profile e2e run --rm --no-deps "${TTY_ARGS[@]}" e2e npx playwright test --grep-invert "@maintenance|@leftovers" "$@" || status=$?

    # The maintenance journeys raise the authoritative `storage/framework/down` flag, and that flag is
    # global to the origin: every other journey running beside them would be answered with a 503. So
    # they run alone, in their own pass, with one worker.
    step "E2E maintenance journeys (serial: these take the whole origin down)"
    dcq --profile e2e run --rm --no-deps "${TTY_ARGS[@]}" e2e npx playwright test --grep @maintenance --workers=1 "$@" || status=$?

    # Unconditionally, through the real authority: a crashed or interrupted pass must never leave the
    # development origin in maintenance mode for the next person.
    php_run php artisan up --no-interaction >/dev/null 2>&1 || true

    # After every other journey, and with the origin up again: the Resources the suite leaves behind must be exactly the demo's
    # (e2e/resources-leftovers.spec.ts). Not a maintenance journey, which takes the origin down.
    step "E2E leftovers (the suite leaves only the demo Resources behind)"
    dcq --profile e2e run --rm --no-deps "${TTY_ARGS[@]}" e2e npx playwright test --grep @leftovers --workers=1 "$@" || status=$?
    e2e_stop_keep_alive

    # The journeys recorded, changed, granted, withdrew and deleted relationships through the real platform: what they left behind,
    # the demo and the personas included, must still agree with the definitions and with Access (ADR 0038, F15).
    step "Relationships integrity after the run (relationships:check)"
    php_run php artisan relationships:check --no-interaction || status=$?

    return "$status"
}
