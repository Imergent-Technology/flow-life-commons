#!/usr/bin/env bash
# Behavioural tests for the ./flow CLI itself. Run by `./flow check repo`.
#
# `gh` is stubbed on PATH, so these assert what ./flow ci *delegates* without a
# GitHub CLI installation, credentials or network access.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
STUB_DIR="$(mktemp -d)"
CALLS="$STUB_DIR/calls"
FAILURES=0
trap 'rm -rf "$STUB_DIR"' EXIT

# A stand-in for gh: records every invocation and answers the handful of
# queries ./flow ci makes. Behaviour is steered by GH_* environment variables.
cat >"$STUB_DIR/gh" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$GH_CALLS"
if [[ "${1:-}" == auth ]]; then exit "${GH_AUTH_EXIT:-0}"; fi
if [[ "${1:-}" == run && "${2:-}" == list && "$*" == *databaseId* ]]; then
    printf '%s\n' "${GH_RUN_ID:-4242}"
    exit 0
fi
if [[ "${1:-}" == run && "${2:-}" == view && "$*" == *conclusion* ]]; then
    printf '%s\n' "${GH_CONCLUSION:-success}"
    exit 0
fi
exit 0
STUB
chmod +x "$STUB_DIR/gh"

pass() { printf '  ok    %s\n' "$1"; }
fail() {
    printf '  FAIL  %s\n' "$1" >&2
    FAILURES=$((FAILURES + 1))
}

# flow ARGS...: run ./flow with the gh stub first on PATH.
flow() {
    GH_CALLS="$CALLS" PATH="$STUB_DIR:$PATH" "$ROOT/flow" "$@"
}

# expect_exit DESC EXPECTED ARGS...
expect_exit() {
    local desc="$1" expected="$2"
    shift 2
    local actual=0
    flow "$@" >/dev/null 2>&1 || actual=$?
    if [[ "$actual" == "$expected" ]]; then
        pass "$desc"
    else
        fail "$desc (expected exit $expected, got $actual)"
    fi
}

# expect_gh_call DESC EXPECTED_ARGS ARGS...
expect_gh_call() {
    local desc="$1" expected="$2"
    shift 2
    : >"$CALLS"
    flow "$@" >/dev/null 2>&1 || true
    if grep -qxF -- "$expected" "$CALLS"; then
        pass "$desc"
    else
        fail "$desc"
        printf '        expected gh call: %s\n' "$expected" >&2
        printf '        actual gh calls : %s\n' "$(paste -sd'|' "$CALLS" 2>/dev/null)" >&2
    fi
}

# expect_output DESC PATTERN ARGS...
# Output is captured rather than piped: most of these commands exit non-zero on
# purpose, and `set -o pipefail` would otherwise report a match as a failure.
expect_output() {
    local desc="$1" pattern="$2"
    shift 2
    local out
    out="$(flow "$@" 2>&1 || true)"
    if [[ "$out" == *"$pattern"* ]]; then
        pass "$desc"
    else
        fail "$desc (output did not contain: $pattern)"
    fi
}

printf 'flow CLI\n'
expect_exit "help exits cleanly" 0 help
expect_output "help documents the ci group" "ci run" help
expect_exit "an unknown command exits 2" 2 definitely-not-a-command

printf 'flow: audit and doctor --production\n'
expect_output "help documents the audit command" "audit" help
expect_output "help documents doctor --production" "doctor [--production]" help
expect_exit "an unknown audit scope is rejected" 1 audit nonsense
expect_exit "an unknown doctor option is rejected" 1 doctor --nonsense
# The deterministic gate must never reach an advisory database: `./flow check` answers the same on any
# machine and offline, and `./flow audit` is the command that deliberately does not.
if grep -q 'cmd_audit\|composer audit\|npm audit' "$ROOT/scripts/commands/check.sh"; then
    fail "./flow check must not run a dependency audit (it needs the network and changes without a commit)"
else
    pass "./flow check does not depend on an advisory database"
fi

printf 'flow check: routine and comprehensive verification (CI-1)\n'
# What ./flow check says it did not run: sourced from the real check.sh, so the text a user reads is the text under test.
notice() { FLOW_ROOT="$ROOT" bash -c 'source "$FLOW_ROOT/scripts/lib/common.sh"; source "$FLOW_ROOT/scripts/commands/check.sh"; verification_notice "$@"' x "$@" 2>&1; }
expect_contains() { # DESC TEXT NEEDLE
    if [[ "$2" == *"$3"* ]]; then pass "$1"; else fail "$1 (did not contain: $3)"; fi
}
expect_lacks() { # DESC TEXT NEEDLE
    if [[ "$2" != *"$3"* ]]; then pass "$1"; else fail "$1 (should not contain: $3)"; fi
}
expect_exit "an unknown check option is rejected" 1 check --nonsense
expect_exit "--mutation is refused with the backend scope" 1 check backend --mutation
expect_exit "--mutation is refused with the frontend scope" 1 check frontend --mutation
expect_output "the refusal names the scope that runs the rehearsal" "check repo --mutation" check backend --mutation
expect_output "help documents --mutation" "--mutation" help
expect_output "help says neither option is release assurance" "Neither is release assurance" help
expect_output "--pgsql is still a backend option of test" "only applies to the backend suite" test frontend --pgsql
routine="$(notice all 0 0)"
expect_contains "a routine run says what it did not include" "$routine" "NOT part of this run"
expect_contains "a routine run says the mutation matrix did not run" "$routine" "mutation matrix (its ordinary tests and registry check ran; 0 mutation experiments)"
expect_contains "a routine run says PostgreSQL did not run" "$routine" "the backend suite on PostgreSQL"
expect_contains "a routine run says E2E did not run" "$routine" "browser E2E"
expect_contains "a routine run says the audit did not run" "$routine" "dependency-security audit"
expect_contains "a routine run says release checks did not run" "$routine" "release-specific checks"
expect_contains "a routine run says it is not release assurance" "$routine" "This is not release assurance"
expect_lacks "a routine run does not claim an extra" "$routine" "Also run, on request"
mutation="$(notice all 0 1)"
expect_contains "--mutation says the full matrix ran" "$mutation" "Also run, on request: the recovery rehearsal's full mutation matrix"
expect_lacks "--mutation does not also say the matrix did not run" "$mutation" "0 mutation experiments"
expect_contains "--mutation is still not release assurance" "$mutation" "This is not release assurance"
expect_contains "--mutation still says E2E did not run" "$mutation" "browser E2E"
expect_contains "--mutation still says PostgreSQL did not run" "$mutation" "the backend suite on PostgreSQL"
pgsql="$(notice all 1 0)"
expect_contains "--pgsql says PostgreSQL ran" "$pgsql" "Also run, on request: the backend suite on PostgreSQL"
expect_contains "--pgsql still says the matrix did not run" "$pgsql" "0 mutation experiments"
both="$(notice all 1 1)"
expect_contains "both options say both ran" "$both" "Also run, on request: the recovery rehearsal's full mutation matrix; the backend suite on PostgreSQL"
expect_contains "both options are still not release assurance" "$both" "This is not release assurance"
frontend="$(notice frontend 0 0)"
expect_contains "the frontend scope says the rehearsal did not run" "$frontend" "the recovery rehearsal (repo checks were not selected)"
expect_contains "the frontend scope says the backend did not run" "$frontend" "backend checks were not selected"
# The wiring: routine passes --routine to the rehearsal and the full run passes nothing; the workflow runs the routine command only.
check_src="$(<"$ROOT/scripts/commands/check.sh")"
# shellcheck disable=SC2016 # the text looked for is the source's own, with its $FLOW_ROOT in it
expect_contains "the routine rehearsal is started with --routine" "$check_src" 'bash "$FLOW_ROOT/scripts/tests/rehearsal.sh" --routine'
# shellcheck disable=SC2016 # as above
expect_contains "the full rehearsal is started with no argument" "$check_src" 'bash "$FLOW_ROOT/scripts/tests/rehearsal.sh"
'
expect_contains "PostgreSQL stays one --pgsql away" "$check_src" 'run_check "Backend tests (Pest, PostgreSQL)" test_backend 1'
ci_run="$(grep -E '^[[:space:]]+run: \./flow check' "$ROOT/.github/workflows/ci.yml" | sed 's/^[[:space:]]*run: //')"
if [[ "$ci_run" == './flow check' ]]; then pass "CI runs ./flow check, routine, and nothing else of ./flow check"; else fail "CI runs '$ci_run', not exactly './flow check'"; fi
if grep -v '^[[:space:]]*#' "$ROOT/.github/workflows/ci.yml" | grep -qE '(--pgsql|--mutation)'; then fail "ci.yml asks for --pgsql or --mutation outside a comment"; else pass "ci.yml does not ask for PostgreSQL or the mutation matrix"; fi

printf 'flow ci: dispatch\n'
expect_exit "a bare 'ci' exits 2" 2 ci
expect_exit "an unknown subcommand exits 2" 2 ci definitely-not-a-subcommand
expect_output "'ci' lists its subcommands" "rerun" ci
expect_exit "'ci --help' exits cleanly" 0 ci --help

printf 'flow ci: delegation to gh\n'
expect_gh_call "run targets the CI workflow" \
    "workflow run ci.yml --ref main" ci run --ref main
expect_gh_call "e2e targets the E2E workflow" \
    "workflow run e2e.yml --ref main" ci e2e --ref main
expect_gh_call "status lists CI runs" \
    "run list --workflow ci.yml --limit 10" ci status
expect_gh_call "status lists E2E runs" \
    "run list --workflow e2e.yml --limit 10" ci status
expect_gh_call "status honours --limit" \
    "run list --workflow ci.yml --limit 3" ci status --limit 3
expect_gh_call "watch resolves the latest run" \
    "run watch 4242 --exit-status" ci watch
expect_gh_call "watch accepts an explicit run id" \
    "run watch 99 --exit-status" ci watch 99
expect_gh_call "rerun re-runs the latest run" \
    "run rerun 4242" ci rerun
expect_gh_call "rerun --failed re-runs failed jobs only" \
    "run rerun 4242 --failed" ci rerun --failed

printf 'flow ci: log selection\n'
GH_CONCLUSION=failure expect_gh_call "a failed run shows only the failed steps" \
    "run view 4242 --log-failed" ci logs
GH_CONCLUSION=failure expect_gh_call "--full overrides that" \
    "run view 4242 --log" ci logs --full
GH_CONCLUSION=success expect_gh_call "a successful run shows the full log" \
    "run view 4242 --log" ci logs

printf 'flow ci: guards\n'
# shellcheck disable=SC2030,SC2031 # each assertion runs in its own subshell on purpose
(
    export GH_AUTH_EXIT=1
    expect_exit "unauthenticated gh fails" 1 ci status
    expect_output "unauthenticated gh explains how to log in" "gh auth login" ci status
) || FAILURES=$((FAILURES + 1))

expect_exit "an unknown ci option is rejected" 1 ci run --nonsense
expect_exit "--limit rejects a non-number" 1 ci status --limit abc

printf 'flow: gh stays confined to the ci group\n'
# Scoped to the CLI implementation; this test file naturally mentions require_gh.
gh_users="$(grep -rl 'require_gh' "$ROOT/flow" "$ROOT/scripts/lib" "$ROOT/scripts/commands" || true)"
if [[ "$gh_users" == "$ROOT/scripts/commands/ci.sh" ]]; then
    pass "require_gh is referenced only by scripts/commands/ci.sh"
else
    fail "require_gh leaked outside the ci group: $gh_users"
fi

if grep -qE 'require_docker|require_setup|php_run|node_run|\bdcq?\b' "$ROOT/scripts/commands/ci.sh"; then
    fail "ci.sh depends on Docker; ci commands must not require the local stack"
else
    pass "ci.sh needs neither Docker nor a set-up environment"
fi

if ((FAILURES > 0)); then
    printf '\n%d CLI check(s) failed\n' "$FAILURES" >&2
    exit 1
fi
printf '\nAll CLI checks passed\n'
