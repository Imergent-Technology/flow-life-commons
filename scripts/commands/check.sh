#!/usr/bin/env bash
# ./flow check [repo|backend|frontend] [--pgsql] [--mutation]
#
# The checks CI cares about; CI runs this same command (docs/development/testing.md).
# Every check runs even if an earlier one fails, then a summary is printed and
# the exit status is non-zero if anything failed. One command per run_check:
# `set -e` does not apply inside functions called from `if`.
#
# This is ROUTINE verification. Two things are left out unless asked for, and the final
# message says which ones this run left out:
#   --pgsql      the backend suite on PostgreSQL (deferred compatibility target, ADR 0039)
#   --mutation   the recovery rehearsal's full mutation matrix (the repo scope runs the
#                rehearsal's ordinary tests and registry check either way)
# Neither is release assurance, and neither is E2E or the dependency audit
# (docs/development/testing.md, "Verification tiers").
#
# The rehearsal's result is not taken from its exit status alone: it must also print exactly one
# REHEARSAL_SUMMARY that says the mode that was asked for passed completely (rehearsal_verified), and
# the developer overrides REHEARSAL_TESTS and REHEARSAL_SRC are refused (refuse_rehearsal_overrides).
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
    local scope=all pgsql=0 mutation=0

    if [[ "${1:-}" =~ ^(repo|backend|frontend|all)$ ]]; then
        scope="$1"
        shift
    fi
    while (($# > 0)); do
        case "$1" in
            --pgsql) pgsql=1 ;;
            --mutation) mutation=1 ;;
            *) die "check: unknown option '$1'" ;;
        esac
        shift
    done
    # The rehearsal belongs to the repo scope: asking for its mutation matrix with a scope that does not run it would run nothing and say nothing, so it is refused.
    if ((mutation == 1)) && [[ "$scope" != all && "$scope" != repo ]]; then
        die "check: --mutation applies to the repo checks (the recovery rehearsal): use './flow check --mutation' or './flow check repo --mutation', not '$scope'"
    fi

    if [[ "$scope" == all || "$scope" == repo ]]; then refuse_rehearsal_overrides; fi

    require_docker
    # The repo checks need only Docker and the PHP image; the others need dependencies.
    [[ "$scope" == repo ]] || require_setup

    if [[ "$scope" == all || "$scope" == repo ]]; then check_repo "$mutation"; fi
    if [[ "$scope" == all || "$scope" == backend ]]; then check_backend "$pgsql"; fi
    if [[ "$scope" == all || "$scope" == frontend ]]; then check_frontend; fi

    if ((${#CHECK_FAILURES[@]} > 0)); then
        bad "${#CHECK_FAILURES[@]} check(s) failed:"
        printf '    - %s\n' "${CHECK_FAILURES[@]}" >&2
        return 1
    fi
    ok "All checks passed (routine verification, not release assurance)"
    verification_notice "$scope" "$pgsql" "$mutation"
}

# REHEARSAL_TESTS and REHEARSAL_SRC are for running scripts/tests/rehearsal.sh directly while working on it: the first makes it run only the tests it names, the second
# points it at another copy of the scripts. Either one in the environment of a verification would test something other than what the result is read as, so a run that
# includes the rehearsal refuses them, SET at all (even to nothing), before it starts anything. They are refused, not unset: the person running it should know.
refuse_rehearsal_overrides() {
    local name
    for name in REHEARSAL_TESTS REHEARSAL_SRC; do
        [[ -v $name ]] || continue
        die "check: $name is set in the environment, and a check that runs the recovery rehearsal does not accept it. REHEARSAL_TESTS (run only some tests) and REHEARSAL_SRC (test another copy of the scripts) are for running scripts/tests/rehearsal.sh directly while developing it, and would make this result mean something else. Unset it ('unset $name') or run './flow check backend' or './flow check frontend', which do not run the rehearsal."
    done
}

# rehearsal_verified MODE COMMAND [ARGS...]: runs the rehearsal and succeeds only if it exits 0 AND its output holds exactly one valid REHEARSAL_SUMMARY that says what MODE
# (routine or full) must have done. The exit status alone is not trusted: a harness that was told to run something else, or that ran nothing, also exits 0. The output
# goes through to the terminal as it is produced; the harness's own failures and diagnostics are not replaced, and a nonzero exit is a failure whatever the summary says.
rehearsal_verified() (
    local mode="$1" out status=0
    shift
    out="$(mktemp)"
    trap 'rm -f "$out"' EXIT
    set -o pipefail # the status below is the harness's, not tee's
    "$@" | tee "$out" || status=$?
    if ((status != 0)); then
        bad "the recovery rehearsal ($mode) exited with status $status"
        return 1
    fi
    verify_rehearsal_summary "$mode" "$out"
)

# verify_rehearsal_summary MODE FILE: the checks on the one machine-readable line (scripts/tests/rehearsal.sh, "how the file is run"), each a reason to refuse. Routine:
# every ordinary test ran and passed, the registry is consistent, and NO mutant ran. Full: the same, and every registered guard was mutated and caught, each counted once.
verify_rehearsal_summary() {
    local mode="$1" file="$2" count line
    local re='^REHEARSAL_SUMMARY mode=([a-z]+) result=([a-z]+) ordinary_run=([0-9]+) ordinary_failed=([0-9]+) registry=([a-z]+) guards_registered=([0-9]+) mutants_executed=([0-9]+) mutants_caught=([0-9]+) matrix=([a-z-]+)( workers=[0-9]+)?$'
    count="$(grep -c '^REHEARSAL_SUMMARY' "$file" || true)"
    if [[ "$count" != 1 ]]; then
        bad "the recovery rehearsal ($mode) exited 0 but printed $count REHEARSAL_SUMMARY line(s), not exactly one: it is not a verification result"
        return 1
    fi
    line="$(grep '^REHEARSAL_SUMMARY' "$file")"
    if [[ ! "$line" =~ $re ]]; then
        bad "the recovery rehearsal ($mode) printed a REHEARSAL_SUMMARY that does not have the expected form: ${line:0:200}"
        return 1
    fi
    local got_mode="${BASH_REMATCH[1]}" result="${BASH_REMATCH[2]}" ran="${BASH_REMATCH[3]}" failed="${BASH_REMATCH[4]}" registry="${BASH_REMATCH[5]}"
    local guards="${BASH_REMATCH[6]}" executed="${BASH_REMATCH[7]}" caught="${BASH_REMATCH[8]}" matrix="${BASH_REMATCH[9]}" workers="${BASH_REMATCH[10]}"
    local why=()
    [[ "$got_mode" == "$mode" ]] || why+=("it reports mode=$got_mode, not $mode")
    [[ "$result" == passed ]] || why+=("result=$result")
    [[ "$registry" == consistent ]] || why+=("registry=$registry")
    ((10#$ran > 0)) || why+=("no ordinary test ran")
    ((10#$failed == 0)) || why+=("$failed ordinary test(s) failed")
    ((10#$guards > 0)) || why+=("no guard is registered")
    if [[ "$mode" == routine ]]; then
        [[ "$matrix" == not-run ]] || why+=("matrix=$matrix, not not-run")
        [[ -z "$workers" ]] || why+=("a routine run reports workers")
        ((10#$executed == 0 && 10#$caught == 0)) || why+=("mutants_executed=$executed mutants_caught=$caught, not 0 and 0")
    else
        [[ "$matrix" == run ]] || why+=("matrix=$matrix, not run")
        ((10#$executed == 10#$guards && 10#$caught == 10#$guards)) || why+=("mutants_executed=$executed mutants_caught=$caught, not both equal to guards_registered=$guards")
    fi
    if ((${#why[@]} > 0)); then
        bad "the recovery rehearsal ($mode) summary is not a passing $mode result: $(printf '%s; ' "${why[@]}" | sed 's/; $//')"
        return 1
    fi
    info "Rehearsal summary verified ($mode): $ran ordinary test(s) passed, $guards guard(s) registered, $executed mutant(s) executed and $caught caught."
}

# What this run did NOT include, said every time: a passing routine run must not be read as more than it is. Release assurance is the complete mutation matrix,
# the browser E2E suite, the dependency-security audit and the release checks, run on the release commit (docs/development/testing.md); no workflow does that yet.
verification_notice() {
    local scope="$1" pgsql="$2" mutation="$3" left=() ran=()
    if [[ "$scope" == all || "$scope" == repo ]]; then
        if ((mutation == 1)); then ran+=("the recovery rehearsal's full mutation matrix"); else left+=("the recovery rehearsal's mutation matrix (its ordinary tests and registry check ran; 0 mutation experiments)"); fi
    else
        left+=("the recovery rehearsal (repo checks were not selected)")
    fi
    if [[ "$scope" == all || "$scope" == backend ]]; then
        if ((pgsql == 1)); then ran+=("the backend suite on PostgreSQL"); else left+=("the backend suite on PostgreSQL (MariaDB is the production target; PostgreSQL is deferred, ADR 0039)"); fi
    else
        left+=("the backend suite on PostgreSQL (backend checks were not selected)")
    fi
    left+=("browser E2E (./flow test e2e)" "the dependency-security audit (./flow audit)" "release-specific checks (./flow release build --dry-run)")
    if ((${#ran[@]} > 0)); then
        info "Also run, on request: $(printf '%s; ' "${ran[@]}" | sed 's/; $//')"
    fi
    info "NOT part of this run: $(printf '%s; ' "${left[@]}" | sed 's/; $//')"
    info "This is not release assurance. The full verification before a release is in docs/development/testing.md (Verification tiers)."
}

check_repo() {
    local mutation="${1:-0}"
    run_check "Compose configuration is valid" dc --profile '*' config --quiet
    run_check "Rehearsal compose configuration is valid" rehearsal_compose_valid
    run_check "Shell scripts (shellcheck)" shellcheck_scripts
    run_check "./flow CLI behaviour" bash "$FLOW_ROOT/scripts/tests/cli.sh"
    run_check "Production public surface (Apache/Caddy contract)" bash "$FLOW_ROOT/scripts/tests/public-surface.sh"
    run_check "Production public surface under real Apache" bash "$FLOW_ROOT/scripts/tests/apache-surface.sh"
    run_check "./flow release tooling" bash "$FLOW_ROOT/scripts/tests/release.sh"
    run_check "Pre-flight SQL in the runbook is the tested file" bash "$FLOW_ROOT/scripts/tests/preflight-sql.sh"
    if ((mutation == 1)); then
        run_check "Recovery rehearsal isolation guards (fake daemon, FULL: ordinary tests and the whole mutation matrix)" rehearsal_verified full bash "$FLOW_ROOT/scripts/tests/rehearsal.sh"
    else
        run_check "Recovery rehearsal isolation guards (fake daemon, ROUTINE: ordinary tests and registry check; mutation matrix NOT RUN)" rehearsal_verified routine bash "$FLOW_ROOT/scripts/tests/rehearsal.sh" --routine
    fi
    run_check "GitHub workflows (actionlint)" actionlint_workflows
    run_check "WordPress companion PHP syntax" lint_wordpress_companion
    run_check "WordPress companion holds no Commons authority (Phase 1)" wordpress_companion_boundary
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
                mirror.gcr.io/koalaman/shellcheck:v0.11.0 -x flow scripts/lib/*.sh scripts/commands/*.sh scripts/rehearsal/*.sh scripts/tests/*.sh
    )
}

# The recovery rehearsal's own compose file (G6 WP3c), which is not part of the development stack. Placeholder credentials stand in for the
# per-run values restore-rehearsal.sh generates; this renders the file and starts nothing.
rehearsal_compose_valid() {
    local env_file status=0
    env_file="$(mktemp)"
    printf 'REHEARSAL_DB_ROOT_PASSWORD=x\nREHEARSAL_DB_APP_PASSWORD=y\nREHEARSAL_RUN_TOKEN=0123456789abcdef0123456789abcdef\n' >"$env_file"
    docker compose -p flc-rehearsal-restore --project-directory "$FLOW_ROOT/scripts/rehearsal" -f "$FLOW_ROOT/scripts/rehearsal/compose.yaml" \
        --env-file "$env_file" config --quiet || status=$?
    rm -f "$env_file"
    return "$status"
}

actionlint_workflows() {
    # Files are passed explicitly so this works without a git repository.
    docker run --rm --user "$(id -u):$(id -g)" -v "$FLOW_ROOT:/repo:ro" -w /repo \
        mirror.gcr.io/rhysd/actionlint:1.7.12 -color .github/workflows/*.yml
}

lint_wordpress_companion() {
    dcq run --rm --no-deps -T -v "$FLOW_ROOT/apps/wordpress-companion:/plugin:ro" platform \
        sh -c 'find /plugin -name "*.php" -print0 | xargs -0 -r -n1 php -l'
}

# The platform image, with the repository read-only: the platform container itself mounts apps/platform only and cannot
# see the companion. See the header of scripts/tests/wordpress-boundary.php for why this is a phase boundary.
wordpress_companion_boundary() {
    dcq run --rm --no-deps -T -v "$FLOW_ROOT:/repo:ro" platform php /repo/scripts/tests/wordpress-boundary.php /repo
}
