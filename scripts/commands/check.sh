#!/usr/bin/env bash
# ./flow check [repo|backend|frontend] [--pgsql]
#
# The checks CI cares about; CI runs this same command (docs/development/testing.md).
# Every check runs even if an earlier one fails, then a summary is printed and
# the exit status is non-zero if anything failed. One command per run_check:
# `set -e` does not apply inside functions called from `if`.
# shellcheck shell=bash

# shellcheck source=scripts/commands/test.sh
source "$FLOW_ROOT/scripts/commands/test.sh" # test_backend / test_frontend

CHECK_FAILURES=()

run_check() {
    local name="$1"
    shift
    step "$name"
    if "$@"; then
        ok "$name"
    else
        bad "$name"
        CHECK_FAILURES+=("$name")
    fi
    echo
}

cmd_check() {
    local scope=all pgsql=0

    if [[ "${1:-}" =~ ^(repo|backend|frontend|all)$ ]]; then
        scope="$1"
        shift
    fi
    while (($# > 0)); do
        case "$1" in
            --pgsql) pgsql=1 ;;
            *) die "check: unknown option '$1'" ;;
        esac
        shift
    done

    require_docker
    # The repo checks need only Docker and the PHP image; the others need dependencies.
    [[ "$scope" == repo ]] || require_setup

    if [[ "$scope" == all || "$scope" == repo ]]; then check_repo; fi
    if [[ "$scope" == all || "$scope" == backend ]]; then check_backend "$pgsql"; fi
    if [[ "$scope" == all || "$scope" == frontend ]]; then check_frontend; fi

    if ((${#CHECK_FAILURES[@]} > 0)); then
        bad "${#CHECK_FAILURES[@]} check(s) failed:"
        printf '    - %s\n' "${CHECK_FAILURES[@]}" >&2
        return 1
    fi
    ok "All checks passed"
}

check_repo() {
    run_check "Compose configuration is valid" dc --profile '*' config --quiet
    run_check "Shell scripts (shellcheck)" shellcheck_scripts
    run_check "./flow CLI behaviour" bash "$FLOW_ROOT/scripts/tests/cli.sh"
    run_check "Production public surface (Apache/Caddy contract)" bash "$FLOW_ROOT/scripts/tests/public-surface.sh"
    run_check "Production public surface under real Apache" bash "$FLOW_ROOT/scripts/tests/apache-surface.sh"
    run_check "./flow release tooling" bash "$FLOW_ROOT/scripts/tests/release.sh"
    run_check "GitHub workflows (actionlint)" actionlint_workflows
    run_check "WordPress companion PHP syntax" lint_wordpress_companion
}

check_backend() {
    local pgsql="$1"
    run_check "Composer manifest is valid" php_run composer validate --strict --no-check-publish
    run_check "Code style (Pint)" php_run vendor/bin/pint --test
    run_check "Static analysis (Larastan, level max)" php_run vendor/bin/phpstan analyse --memory-limit=1G --no-progress
    run_check "Backend tests incl. architecture (Pest, MariaDB)" test_backend 0
    if [[ "$pgsql" == 1 ]]; then
        run_check "Backend tests (Pest, PostgreSQL)" test_backend 1
    fi
}

check_frontend() {
    run_check "TypeScript (strict)" node_run npm run typecheck
    run_check "ESLint" node_run npm run lint
    run_check "Prettier" node_run npm run format:check
    run_check "Frontend tests (Vitest)" test_frontend
    run_check "Production build (Vite + Tailwind)" node_run npm run build
    run_check "Build output verification" node_run npm run verify:build
}

# --- repo check helpers (pinned images; run as the invoking user) ----------

shellcheck_scripts() {
    (
        cd "$FLOW_ROOT" &&
            docker run --rm --user "$(id -u):$(id -g)" -v "$FLOW_ROOT:/mnt:ro" -w /mnt \
                koalaman/shellcheck:v0.11.0 -x flow scripts/lib/*.sh scripts/commands/*.sh scripts/tests/*.sh
    )
}

actionlint_workflows() {
    # Files are passed explicitly so this works without a git repository.
    docker run --rm --user "$(id -u):$(id -g)" -v "$FLOW_ROOT:/repo:ro" -w /repo \
        rhysd/actionlint:1.7.12 -color .github/workflows/*.yml
}

lint_wordpress_companion() {
    dcq run --rm --no-deps -T -v "$FLOW_ROOT/apps/wordpress-companion:/plugin:ro" platform \
        sh -c 'find /plugin -name "*.php" -print0 | xargs -0 -r -n1 php -l'
}
