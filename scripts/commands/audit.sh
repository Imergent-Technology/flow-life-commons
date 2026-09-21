#!/usr/bin/env bash
# ./flow audit [backend|frontend]: dependency vulnerability audit.
#
# DELIBERATELY NOT part of `./flow check`. Every other check in this repository is a pure function of
# the source tree: the same commit gives the same answer on any machine, today or next year. An audit
# is not — it asks two public advisory databases what they currently know, so its answer changes
# without a commit and is unavailable without a network. Folding it into the ordinary gate would make
# the gate non-deterministic and make an outage at Packagist or npm look like a broken branch.
#
# So it is its own command, run on purpose, and mirrored by a scheduled GitHub Actions workflow
# (.github/workflows/security-audit.yml) that runs weekly, on a lockfile change, and on demand. A
# developer investigating what CI reported runs exactly this command locally.
#
# It REPORTS. It never rewrites a dependency graph: no `composer update`, and emphatically no
# `npm audit fix --force`, which silently installs major versions to make a number go down.
# shellcheck shell=bash

cmd_audit() {
    local scope=all
    if [[ "${1:-}" =~ ^(backend|frontend|all)$ ]]; then
        scope="$1"
        shift
    fi
    (($# == 0)) || die "audit: unknown option '$1'"

    require_docker
    require_setup

    local failures=()

    if [[ "$scope" == all || "$scope" == backend ]]; then
        step "Composer advisories (PHP)"
        # --locked audits the LOCKFILE, which is what a deployment installs, rather than whatever
        # happens to be in vendor/ on this machine.
        if php_run composer audit --locked --no-interaction; then
            ok "No known advisories for the PHP dependencies"
        else
            bad "composer audit reported findings"
            failures+=("composer")
        fi
        echo

        step "Abandoned PHP packages"
        # Abandoned is not a vulnerability, so it must not fail the audit; it is a slow-moving risk
        # (nobody will publish a fix) that belongs in a review rather than in a gate.
        php_run composer audit --locked --abandoned=report --no-interaction >/dev/null 2>&1 || true
        php_run composer show --locked --direct 2>/dev/null | grep -i abandoned || info "    none reported"
        echo
    fi

    if [[ "$scope" == all || "$scope" == frontend ]]; then
        step "npm advisories (Guardian Console)"
        # Production dependencies first, because a finding there is what actually reaches a browser.
        if node_run npm audit --omit=dev; then
            ok "No known advisories for the Console's runtime dependencies"
        else
            bad "npm audit reported findings in RUNTIME dependencies"
            failures+=("npm (runtime)")
        fi
        echo

        step "npm advisories including development tooling"
        # Build and test tooling never reaches a browser, so a finding here is a different question:
        # could it compromise a developer's machine or the build? Reported, and deliberately not fatal.
        node_run npm audit || warn "findings in development-only dependencies: judge them on whether they can affect the build (see docs/development/testing.md)"
        echo
    fi

    if ((${#failures[@]} > 0)); then
        bad "${#failures[@]} audit(s) reported findings:"
        printf '    - %s\n' "${failures[@]}" >&2
        info "Inspect each one: is the package runtime or development-only, is this application on the affected path, and is there a safe compatible upgrade? Record the answer (docs/security/dependencies.md)."
        return 1
    fi
    ok "No known advisories in runtime dependencies"
}
