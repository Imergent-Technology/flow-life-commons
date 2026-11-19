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

# C1: a verification must not be able to pass without being the verification. The developer overrides of the rehearsal are refused before anything is started
# (so these are quick, and `timeout` ends one that was not refused instead of letting it run the checks), and the outer check does not trust the rehearsal's exit
# status: it needs exactly one valid summary of the right mode. The summary cases run the real check.sh against stand-in harnesses that print what they are told to.
printf 'flow check: the rehearsal overrides are refused, and the rehearsal summary is verified (C1)\n'
refused() { # DESC VARIABLE VALUE ARGS...: ./flow check ARGS with VARIABLE=VALUE inherited must exit 1, say which variable, and start nothing
    local desc="$1" name="$2" value="$3" rc=0 out
    shift 3
    out="$(env "$name=$value" GH_CALLS="$CALLS" PATH="$STUB_DIR:$PATH" timeout 120 "$ROOT/flow" "$@" 2>&1)" || rc=$?
    if [[ "$rc" == 1 && "$out" == *"check: $name is set"* && "$out" != *'==>'* ]]; then pass "$desc"; else fail "$desc (exit $rc; output: ${out:0:200})"; fi
}
refused "a full check refuses REHEARSAL_TESTS naming a real test" REHEARSAL_TESTS t_surface_no_restore_command check repo --mutation
refused "a full check refuses a whitespace-only REHEARSAL_TESTS" REHEARSAL_TESTS ' ' check repo --mutation
refused "a full check refuses an empty REHEARSAL_TESTS" REHEARSAL_TESTS '' check repo --mutation
refused "a full check refuses REHEARSAL_TESTS naming an unknown test" REHEARSAL_TESTS t_no_such_test check repo --mutation
refused "a routine check refuses REHEARSAL_TESTS naming a real test" REHEARSAL_TESTS t_surface_no_restore_command check repo
refused "a routine check refuses an empty REHEARSAL_TESTS" REHEARSAL_TESTS '' check repo
refused "a check of everything refuses REHEARSAL_TESTS" REHEARSAL_TESTS t_surface_no_restore_command check
refused "a check of everything with --mutation refuses REHEARSAL_TESTS" REHEARSAL_TESTS t_surface_no_restore_command check --mutation
refused "a full check refuses REHEARSAL_SRC" REHEARSAL_SRC /tmp check repo --mutation
refused "a routine check refuses REHEARSAL_SRC" REHEARSAL_SRC /tmp check repo
refused "a check refuses an empty REHEARSAL_SRC" REHEARSAL_SRC '' check repo
refusal="$(REHEARSAL_TESTS=t_x timeout 120 "$ROOT/flow" check repo 2>&1 || true)"
expect_contains "the refusal says what the overrides are for" "$refusal" "for running scripts/tests/rehearsal.sh directly while developing it"
expect_contains "the refusal says how to proceed" "$refusal" "unset REHEARSAL_TESTS"
# The supported worker-count setting is left to the rehearsal itself, which validates it.
if grep -q 'REHEARSAL_WORKERS' "$ROOT/scripts/commands/check.sh"; then fail "check.sh refuses or handles REHEARSAL_WORKERS, which is supported"; else pass "REHEARSAL_WORKERS, the supported setting, is left to the rehearsal"; fi

# A stand-in for scripts/tests/rehearsal.sh: prints $STANDIN_OUT (as it is, newlines and all) and exits $STANDIN_RC.
cat >"$STUB_DIR/harness.sh" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$STANDIN_OUT"
exit "${STANDIN_RC:-0}"
STUB
chmod +x "$STUB_DIR/harness.sh"
verified() { # MODE RC OUTPUT: the real check.sh's rehearsal_verified, run against the stand-in; prints its output, and sets VRC
    VRC=0
    VOUT="$(STANDIN_OUT="$3" STANDIN_RC="$2" FLOW_ROOT="$ROOT" bash -c 'source "$FLOW_ROOT/scripts/lib/common.sh"; source "$FLOW_ROOT/scripts/commands/check.sh"; rehearsal_verified "$@"' x "$1" bash "$STUB_DIR/harness.sh" 2>&1)" || VRC=$?
}
expect_verified() { # DESC MODE RC OUTPUT [NEEDLE]
    verified "$2" "$3" "$4"
    if [[ "$VRC" == 0 && "$VOUT" == *'Rehearsal summary verified'* ]]; then pass "$1"; else fail "$1 (exit $VRC: ${VOUT:0:300})"; fi
}
expect_unverified() { # DESC MODE RC OUTPUT NEEDLE
    verified "$2" "$3" "$4"
    if [[ "$VRC" != 0 && "$VOUT" != *'Rehearsal summary verified'* && "$VOUT" == *"$5"* ]]; then pass "$1"; else fail "$1 (exit $VRC, wanted a failure saying: $5; got: ${VOUT:0:300})"; fi
}
routine_ok='REHEARSAL_SUMMARY mode=routine result=passed ordinary_run=283 ordinary_failed=0 registry=consistent guards_registered=316 mutants_executed=0 mutants_caught=0 matrix=not-run'
full_ok='REHEARSAL_SUMMARY mode=full result=passed ordinary_run=283 ordinary_failed=0 registry=consistent guards_registered=316 mutants_executed=316 mutants_caught=316 matrix=run workers=4'
expect_verified "a valid routine summary succeeds" routine 0 "harness output
$routine_ok
Routine rehearsal checks passed"
expect_verified "a valid full summary succeeds" full 0 "harness output
$full_ok
All rehearsal guard tests passed"
expect_unverified "a harness that exits 0 with no summary fails (routine)" routine 0 "Rehearsal guard tests
  ok    one_test" "printed 0 REHEARSAL_SUMMARY"
expect_unverified "a harness that exits 0 with no summary fails (full)" full 0 "  ok    one_test
All rehearsal guard tests passed" "printed 0 REHEARSAL_SUMMARY"
expect_unverified "a full run's summary is refused for a routine check" routine 0 "$full_ok" "mode=full, not routine"
expect_unverified "a routine summary is refused for a full check" full 0 "$routine_ok" "mode=routine, not full"
expect_unverified "a duplicated summary fails" routine 0 "$routine_ok
$routine_ok" "printed 2 REHEARSAL_SUMMARY"
expect_unverified "two different summaries fail" full 0 "$routine_ok
$full_ok" "printed 2 REHEARSAL_SUMMARY"
expect_unverified "a malformed summary fails" routine 0 "REHEARSAL_SUMMARY mode=routine result=passed" "does not have the expected form"
expect_unverified "a summary with an extra field fails" routine 0 "$routine_ok extra=1" "does not have the expected form"
expect_unverified "a summary with a non-numeric count fails" routine 0 "${routine_ok/ordinary_run=283/ordinary_run=many}" "does not have the expected form"
expect_unverified "a failed result fails" routine 0 "${routine_ok/result=passed/result=failed}" "result=failed"
expect_unverified "an inconsistent registry fails" routine 0 "${routine_ok/registry=consistent/registry=inconsistent}" "registry=inconsistent"
expect_unverified "no ordinary test having run fails" routine 0 "${routine_ok/ordinary_run=283/ordinary_run=0}" "no ordinary test ran"
expect_unverified "a failed ordinary test fails" routine 0 "${routine_ok/ordinary_failed=0/ordinary_failed=2}" "2 ordinary test(s) failed"
expect_unverified "no registered guard fails" routine 0 "${routine_ok/guards_registered=316/guards_registered=0}" "no guard is registered"
expect_unverified "a routine run that executed a mutant fails" routine 0 "${routine_ok/mutants_executed=0/mutants_executed=1}" "not 0 and 0"
expect_unverified "a routine run that says the matrix ran fails" routine 0 "${routine_ok/matrix=not-run/matrix=run}" "matrix=run, not not-run"
expect_unverified "a full run that says the matrix did not run fails" full 0 "${full_ok/matrix=run/matrix=not-run}" "matrix=not-run, not run"
expect_unverified "a full run with no mutant executed fails" full 0 "${full_ok/mutants_executed=316/mutants_executed=0}" "not both equal to guards_registered=316"
expect_unverified "a full run with fewer mutants executed than guards fails" full 0 "${full_ok/mutants_executed=316/mutants_executed=315}" "mutants_executed=315"
expect_unverified "a full run with fewer mutants caught than executed fails" full 0 "${full_ok/mutants_caught=316/mutants_caught=315}" "mutants_caught=315"
expect_unverified "a full run with more mutants than guards fails" full 0 "${full_ok/mutants_executed=316/mutants_executed=317}" "mutants_executed=317"
expect_unverified "a full run with zero mutants and zero guards fails" full 0 "${full_ok//316/0}" "no guard is registered"
expect_unverified "a nonzero exit fails even with a valid routine summary" routine 1 "$routine_ok" "exited with status 1"
expect_unverified "a nonzero exit fails even with a valid full summary" full 3 "$full_ok" "exited with status 3"
verified routine 1 "REHEARSAL_SUMMARY mode=routine result=failed ordinary_run=283 ordinary_failed=1 registry=consistent guards_registered=316 mutants_executed=0 mutants_caught=0 matrix=not-run"
expect_contains "the harness's own output is still shown when it fails" "$VOUT" "ordinary_failed=1"
# The wiring: both modes go through the verification, and nothing starts the rehearsal for a check without it.
# shellcheck disable=SC2016 # the text looked for is the source's own, with its $FLOW_ROOT in it
expect_contains "the routine rehearsal is run through the summary verification" "$check_src" 'rehearsal_verified routine bash "$FLOW_ROOT/scripts/tests/rehearsal.sh" --routine'
# shellcheck disable=SC2016 # the text looked for is the source's own, with its $FLOW_ROOT in it
expect_contains "the full rehearsal is run through the summary verification" "$check_src" 'rehearsal_verified full bash "$FLOW_ROOT/scripts/tests/rehearsal.sh"'
# shellcheck disable=SC2016 # as above
if grep -E 'run_check .*bash "\$FLOW_ROOT/scripts/tests/rehearsal.sh' "$ROOT/scripts/commands/check.sh" | grep -qv 'rehearsal_verified'; then fail "a check starts the rehearsal without verifying its summary"; else pass "no check starts the rehearsal without verifying its summary"; fi

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
