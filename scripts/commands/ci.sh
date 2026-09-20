#!/usr/bin/env bash
# ./flow ci run|e2e|status|watch|logs|rerun
#
# A deliberately thin wrapper over `gh` for this project's two workflows. It is
# not a general GitHub client: authentication, remotes, secrets, artifacts and
# dispatching arbitrary workflows stay plain `git`/`gh` commands.
#
# `gh` is required ONLY here. Every other ./flow command works without it.
# The provider-independent local equivalent of CI remains `./flow check --pgsql`.
# shellcheck shell=bash

CI_WORKFLOW='ci.yml'   # .github/workflows/ci.yml  — "CI"
E2E_WORKFLOW='e2e.yml' # .github/workflows/e2e.yml — "E2E smoke"

cmd_ci() {
    local sub="${1:-}"
    shift || true

    case "$sub" in
        run) ci_trigger "$CI_WORKFLOW" "CI" "$@" ;;
        e2e) ci_trigger "$E2E_WORKFLOW" "E2E smoke" "$@" ;;
        status) ci_status "$@" ;;
        watch) ci_watch "$@" ;;
        logs) ci_logs "$@" ;;
        rerun) ci_rerun "$@" ;;
        -h | --help) ci_usage ;;
        '')
            ci_usage >&2
            return 2
            ;;
        *)
            printf 'flow: unknown ci subcommand "%s"\n\n' "$sub" >&2
            ci_usage >&2
            return 2
            ;;
    esac
}

ci_usage() {
    cat <<'USAGE'
Usage: ./flow ci <subcommand>

  run [--ref REF]               Trigger the CI workflow (default ref: current branch)
  e2e [--ref REF]               Trigger the E2E smoke workflow
  status [--limit N]            Recent runs of both workflows
  watch [RUN_ID]                Follow a run to completion (default: latest)
  logs [RUN_ID] [--full]        Logs for a run; failed steps only when it failed
  rerun [RUN_ID] [--failed]     Re-run a run (--failed: only the failed jobs)

Needs the GitHub CLI (gh); no other ./flow command does.
Anything else GitHub-related (auth, remotes, secrets, artifacts) is plain git/gh.
Local equivalent of the CI checks: ./flow check --pgsql
USAGE
}

# --- preconditions ----------------------------------------------------------

# Checked here and nowhere else, so the rest of ./flow stays gh-free.
require_gh() {
    if ! command -v gh >/dev/null 2>&1; then
        die "the GitHub CLI (gh) is not installed, and ./flow ci needs it.
    Debian/Ubuntu: sudo apt install gh
    Arch:          sudo pacman -S github-cli
    Other:         https://cli.github.com
Every other ./flow command works without gh; ./flow check --pgsql runs the same checks locally."
    fi

    if ! gh auth status >/dev/null 2>&1; then
        die "the GitHub CLI is not authenticated. Run:
    gh auth login -s workflow
The 'workflow' scope is what lets you push changes to .github/workflows."
    fi

    git remote get-url origin >/dev/null 2>&1 ||
        die "this repository has no 'origin' remote; ./flow ci acts on the GitHub remote."
}

# Most recent run of any workflow in this repository.
latest_run_id() {
    gh run list --limit 1 --json databaseId --jq '.[0].databaseId // empty'
}

resolve_run_id() {
    local id="${1:-}"
    if [[ -n "$id" ]]; then
        printf '%s' "$id"
        return 0
    fi

    id="$(latest_run_id)"
    [[ -n "$id" ]] || die "no workflow runs exist yet. Trigger one with: ./flow ci run"
    printf '%s' "$id"
}

# Consumes a leading numeric run id, if present, into RUN_ID.
take_run_id() {
    RUN_ID=''
    if [[ "${1:-}" =~ ^[0-9]+$ ]]; then
        RUN_ID="$1"
        return 0
    fi
    return 1
}

# --- subcommands ------------------------------------------------------------

ci_trigger() {
    local workflow="$1" label="$2" ref=''
    shift 2

    while (($# > 0)); do
        case "$1" in
            --ref)
                [[ -n "${2:-}" ]] || die "ci: --ref needs a branch or tag"
                ref="$2"
                shift
                ;;
            *) die "ci: unknown option '$1'" ;;
        esac
        shift
    done

    require_gh
    [[ -n "$ref" ]] || ref="$(git rev-parse --abbrev-ref HEAD)"

    step "Triggering $label on $ref"
    if ! gh workflow run "$workflow" --ref "$ref"; then
        die "could not trigger $label.
GitHub accepts a manual run only when the workflow file on the DEFAULT branch
carries a workflow_dispatch trigger, so push first (git push), then retry."
    fi

    ok "$label requested on $ref"
    info "  Follow it with: ./flow ci watch"
}

ci_status() {
    local limit=10

    while (($# > 0)); do
        case "$1" in
            --limit)
                [[ "${2:-}" =~ ^[0-9]+$ ]] || die "ci status: --limit needs a number"
                limit="$2"
                shift
                ;;
            *) die "ci status: unknown option '$1'" ;;
        esac
        shift
    done

    require_gh

    step "CI"
    gh run list --workflow "$CI_WORKFLOW" --limit "$limit" || info "  (none yet)"
    echo
    step "E2E smoke"
    gh run list --workflow "$E2E_WORKFLOW" --limit "$limit" || info "  (none yet)"
}

ci_watch() {
    local id=''
    if take_run_id "${1:-}"; then
        id="$RUN_ID"
        shift
    fi
    (($# == 0)) || die "ci watch: unexpected argument '$1'"

    require_gh
    id="$(resolve_run_id "$id")"

    step "Watching run $id (Ctrl-C stops watching; the run continues)"
    # --exit-status makes a failed run fail this command, so it composes in scripts.
    gh run watch "$id" --exit-status
}

ci_logs() {
    local id='' full=0
    if take_run_id "${1:-}"; then
        id="$RUN_ID"
        shift
    fi
    while (($# > 0)); do
        case "$1" in
            --full) full=1 ;;
            *) die "ci logs: unknown option '$1'" ;;
        esac
        shift
    done

    require_gh
    id="$(resolve_run_id "$id")"

    local conclusion
    conclusion="$(gh run view "$id" --json conclusion --jq '.conclusion // empty')"

    # --log-failed prints nothing for a run that succeeded, so only use it when
    # there is a failure to show.
    if ((full == 0)) && [[ "$conclusion" == "failure" ]]; then
        step "Failed steps of run $id (--full for the whole log)"
        gh run view "$id" --log-failed
    else
        step "Full log of run $id"
        gh run view "$id" --log
    fi
}

ci_rerun() {
    local id='' failed_only=0
    if take_run_id "${1:-}"; then
        id="$RUN_ID"
        shift
    fi
    while (($# > 0)); do
        case "$1" in
            --failed) failed_only=1 ;;
            *) die "ci rerun: unknown option '$1'" ;;
        esac
        shift
    done

    require_gh
    id="$(resolve_run_id "$id")"

    if ((failed_only == 1)); then
        step "Re-running the failed jobs of run $id"
        gh run rerun "$id" --failed
    else
        step "Re-running run $id"
        gh run rerun "$id"
    fi

    ok "Requested. Follow it with: ./flow ci watch"
}
