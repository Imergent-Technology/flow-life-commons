#!/usr/bin/env bash
# ./flow release build | inspect | migrations
#
# The DEVELOPER-SIDE half of the release model (docs/adr/0027-release-and-deployment-model.md,
# "Production operations boundary"): build an artifact from an exact ref, inspect one, list a release's
# migrations. Pure functions of a commit, run on a developer machine, holding no production credentials.
#
# There is deliberately NO `deploy`, `backup`, `restore` or `rollback` here, and this file must never
# reach the production host: putting a release on the host, entering maintenance, backing up, migrating,
# switching `current` and rolling back are explicit operator actions run from docs/runbooks/deployment.md.
# scripts/tests/release.sh pins both facts.
# shellcheck shell=bash

# shellcheck source=scripts/lib/release.sh
source "$FLOW_ROOT/scripts/lib/release.sh"

release_usage() {
    cat <<'USAGE'
Usage: ./flow release <command> [args]

  build --ref REF --previous REF|none --schema-rollback VALUE [options]
        Build a release artifact from REF in an isolated worktree, from the lockfiles.
          --ref REF                    an annotated tag reachable from main (ADR 0027)
          --previous REF|none          the release CURRENTLY ON THE HOST, named by you, or 'none'
                                       for a first release. Never inferred: tags may never have
                                       been deployed.
          --schema-rollback VALUE      not-applicable | code-only | restore-required. A person
                                       decides; see docs/runbooks/deployment.md section 3.
          --acknowledge-scanner-findings
                                       required only if the migration scanner reported findings
                                       and VALUE is less conservative than restore-required
          --classified-by "Name <e>"   who supplied VALUE (default: git config user.name/email)
          --allow-untagged             build a ref that is not an annotated tag on main; stamped
                                       in release.json and shown by inspect
          --out DIR                    output directory (default: dist/releases; relative paths
                                       are from the repository root)
          --dry-run                    run every check, then stop before building anything

  inspect ARTIFACT
        Verify the checksum, validate the artifact, and print its manifest. Refuses anything
        malformed. Relative paths are from the repository root.

  migrations [--ref REF] [--previous REF|none]
        List a release's migrations and the scanner's warnings. Default REF is HEAD.

This tool builds and inspects. It never talks to the production host: deployment is a runbook.
USAGE
}

cmd_release() {
    local sub="${1:-}"
    shift || true
    case "$sub" in
        build) release_build "$@" ;;
        inspect) release_inspect "$@" ;;
        migrations) release_migrations "$@" ;;
        help | -h | --help)
            release_usage
            ;;
        *)
            [[ -z "$sub" ]] || printf 'flow: unknown release command "%s"\n\n' "$sub" >&2
            release_usage >&2
            return 2
            ;;
    esac
}

# release_split_args ARGS...: turn --opt=value into --opt value (into REL_ARGS).
release_split_args() {
    local a
    REL_ARGS=()
    for a in "$@"; do
        if [[ "$a" == --*=* ]]; then
            REL_ARGS+=("${a%%=*}" "${a#*=}")
        else
            REL_ARGS+=("$a")
        fi
    done
}

release_need_value() {
    (($# >= 2)) || die "release: $1 needs a value"
}

release_default_classifier() {
    local name email
    name="$(git config user.name 2>/dev/null || true)"
    email="$(git config user.email 2>/dev/null || true)"
    if [[ -n "$name" && -n "$email" ]]; then
        printf '%s <%s>' "$name" "$email"
    else
        printf '%s' "${name:-$email}"
    fi
}

release_build() {
    local ref="" previous="" rollback="" ack=0 by="" allow_untagged=0 dry=0 out="dist/releases"
    release_split_args "$@"
    set -- "${REL_ARGS[@]}"
    while (($# > 0)); do
        case "$1" in
            --ref) release_need_value "$@"; ref="$2"; shift ;;
            --previous) release_need_value "$@"; previous="$2"; shift ;;
            --schema-rollback) release_need_value "$@"; rollback="$2"; shift ;;
            --classified-by) release_need_value "$@"; by="$2"; shift ;;
            --out) release_need_value "$@"; out="$2"; shift ;;
            --acknowledge-scanner-findings) ack=1 ;;
            --allow-untagged) allow_untagged=1 ;;
            --dry-run) dry=1 ;;
            *) die "release build: unknown option '$1' (see ./flow release --help)" ;;
        esac
        shift
    done
    [[ -n "$ref" ]] || die "release build: --ref is required (an annotated tag reachable from main)"
    [[ -n "$previous" ]] || die "release build: --previous is required: the release currently on the host, as a ref, or 'none' for a first release. The build never guesses it (tags may never have been deployed)."
    [[ -n "$rollback" ]] || die "release build: --schema-rollback is required (${RELEASE_ROLLBACK_VALUES[*]}). A person decides it; the tooling never does."
    [[ "$out" == /* ]] || out="$FLOW_ROOT/$out"

    # --- Facts about the source -------------------------------------------------------------------
    local commit prev_commit="" prev_kind=ref override=0
    commit="$(release_resolve_commit "$ref")" || die "release: cannot resolve --ref '$ref' to a commit."
    if [[ "$previous" == none ]]; then
        prev_kind=none
    else
        prev_commit="$(release_resolve_commit "$previous")" ||
            die "release: cannot resolve --previous '$previous' to a commit (use a ref, or 'none' for a first release)."
        git merge-base --is-ancestor "$prev_commit" "$commit" 2>/dev/null ||
            warn "--previous ${prev_commit:0:7} is not an ancestor of ${commit:0:7}: the migration comparison is between two unrelated histories."
    fi

    release_provenance "$ref" "$commit"
    if ((SRC_ANNOTATED == 0 || SRC_ON_MAIN == 0)); then
        if ((allow_untagged == 0)); then
            bad "'$ref' is not an annotated tag reachable from main (ADR 0027):"
            ((SRC_ANNOTATED == 1)) || bad "  - it is not an annotated tag (a branch, a sha and a lightweight tag all fail)"
            ((SRC_ON_MAIN == 1)) || bad "  - ${commit:0:7} is not reachable from main"
            die "tag the release ('git tag -a vX.Y.Z') on main and build the tag; or, for a rehearsal that must never be mistaken for a release, pass --allow-untagged."
        fi
        override=1
        warn "--allow-untagged: building '$ref' without the ADR 0027 provenance. The artifact is stamped as such and will not carry a version name."
    fi

    if git cat-file -e "$commit:$RELEASE_PLATFORM/resources/views" 2>/dev/null; then
        die "release: resources/views now exists at this commit. The approved cache sequence (config:cache, event:cache, route:cache) deliberately excludes view:cache because there were no views (ADR 0027, Optimization model). Decide whether view:cache now belongs in the release, and update the ADR and this tooling, before building."
    fi

    release_migration_diff "$commit" "${prev_commit:-none}"
    release_check_classification "$rollback" "$previous" "$ack"

    if [[ -z "$by" ]]; then
        by="$(release_default_classifier)"
        [[ -n "$by" ]] || die "release: cannot tell who is supplying the classification: git has no user.name/user.email. Pass --classified-by \"Name <email>\"."
    fi
    [[ "$by" != *[[:cntrl:]]* && ${#by} -le 200 ]] || die "release: --classified-by must be at most 200 characters with no control characters."

    # --- Identity of the release ------------------------------------------------------------------
    local epoch="${SOURCE_DATE_EPOCH:-$(date +%s)}"
    [[ "$epoch" =~ ^[0-9]+$ ]] || die "release: SOURCE_DATE_EPOCH must be a whole number of seconds."
    local release_id built_at version="" name
    release_id="$(date -u -d "@$epoch" +%Y%m%dT%H%M%S)-${commit:0:7}"
    built_at="$(date -u -d "@$epoch" +%Y-%m-%dT%H:%M:%SZ)"
    ((SRC_ANNOTATED == 1)) && version="$SRC_TAG"
    if [[ -n "$version" && $override == 0 ]]; then
        name="commons-$version.tar.gz"
    else
        name="commons-$release_id.tar.gz"
    fi
    [[ "$name" != */* && "$name" != .* ]] || die "release: refusing an unsafe artifact name '$name'."
    if [[ -e "$out/$name" || -e "$out/$name.sha256" ]]; then
        die "release: $out/$name already exists. Artifacts are never overwritten; remove it deliberately or choose another --out."
    fi

    step "Release plan"
    info "  release id   $release_id"
    info "  artifact     $out/$name"
    info "  source       $ref -> $commit"
    info "  previous     $previous${prev_commit:+ -> $prev_commit}"
    release_print_migrations
    info "  classified   $rollback by $by (acknowledgement required: $ACK_REQUIRED, provided: $ack)"
    if ((dry == 1)); then
        ok "Dry run: every check passed. Nothing was built and nothing was written."
        return 0
    fi

    # --- Build ------------------------------------------------------------------------------------
    require_docker
    release_require_images
    release_arm_cleanup
    release_mktemp
    local work="$REPLY" wt stage
    wt="$work/src"
    stage="$work/stage"
    RELEASE_WORKTREE="$wt"

    step "Checking out $commit in an isolated worktree (your working tree is not used)"
    git -c core.hooksPath=/dev/null worktree add --detach --quiet "$wt" "$commit" ||
        die "release: could not create the isolated worktree."

    release_install_dependencies "$wt"
    release_build_console "$wt"

    step "Assembling the artifact"
    release_stage "$wt" "$stage"
    release_cache_smoke "$stage"

    RM_RELEASE_ID="$release_id" RM_VERSION="$version" RM_BUILT_AT="$built_at" RM_REF="$ref" RM_COMMIT="$commit"
    RM_OVERRIDE="$override" RM_PREVIOUS_KIND="$prev_kind" RM_PREVIOUS_REF="$previous" RM_PREVIOUS_COMMIT="$prev_commit"
    RM_SCHEMA_ROLLBACK="$rollback" RM_CLASSIFIED_BY="$by" RM_ACK_PROVIDED="$ack"
    RM_COMPOSER_LOCK_SHA="$(git show "$commit:$RELEASE_PLATFORM/composer.lock" | sha256sum | cut -c1-64)"
    RM_PACKAGE_LOCK_SHA="$(git show "$commit:$RELEASE_CONSOLE/package-lock.json" | sha256sum | cut -c1-64)"
    release_write_manifest "$stage/release.json"

    step "Packaging"
    release_package "$stage" "$work/$name" "$epoch"
    (cd "$work" && sha256sum "$name" >"$name.sha256")

    # The artifact is judged as it will ship: re-extracted from the final tarball, not the staging tree.
    step "Verifying the packaged artifact"
    if ! release_verify_artifact "$work/$name"; then
        die "release: the artifact just built failed its own validation. Nothing was written to $out."
    fi

    mkdir -p "$out"
    RELEASE_PARTIALS+=("$out/.$name.partial" "$out/.$name.sha256.partial")
    cp "$work/$name" "$out/.$name.partial"
    cp "$work/$name.sha256" "$out/.$name.sha256.partial"
    # -n (no-clobber): the existence check above ran before this build started, and a build can take
    # minutes, so a concurrent build finishing in between is a real window, not a hypothetical one.
    # -n makes this rename refuse rather than overwrite if that happened, and the tarball is moved into
    # place FIRST and checked before the checksum is touched at all: a torn pairing (one build's
    # tarball with a different build's checksum) can only happen if something removes files out from
    # under this process between the two lines, which is a different failure than a race between two
    # runs of this command.
    mv -n "$out/.$name.partial" "$out/$name" ||
        die "release: $out/$name was published by another build while this one was running. This build's output was discarded; nothing of the other build's was touched."
    mv -n "$out/.$name.sha256.partial" "$out/$name.sha256" ||
        die "release: $out/$name.sha256 was published by another build while this one's tarball, just placed at $out/$name, was still being finished. Do not trust that pairing — remove both and re-run whichever build you meant to keep."

    echo
    ok "Built $release_id"
    info "  $out/$name"
    info "  $out/$name.sha256"
    info "  $(du -h "$out/$name" | cut -f1), sha256 $(cut -c1-64 "$out/$name.sha256")"
    info "Inspect it: ./flow release inspect $out/$name"
    if ((override == 1)); then
        warn "Built with --allow-untagged: not a release. It does not meet the ADR 0027 provenance rule."
    fi
    info "This tool stops here. Uploading and deploying are operator actions: docs/runbooks/deployment.md."
}

release_inspect() {
    (($# == 1)) || die "usage: ./flow release inspect <artifact.tar.gz>"
    [[ "$1" != -* ]] || die "release inspect: unknown option '$1'"
    local artifact="$1"
    [[ "$artifact" == /* ]] || artifact="$FLOW_ROOT/$artifact"

    require_docker
    release_require_images
    release_arm_cleanup
    step "Inspecting $(basename "$artifact")"
    if release_verify_artifact "$artifact"; then
        ok "Artifact is valid"
    else
        bad "Artifact REJECTED. Do not deploy it."
        return 1
    fi
}

release_migrations() {
    local ref="HEAD" previous="none" have_previous=0 commit prev_commit=""
    release_split_args "$@"
    set -- "${REL_ARGS[@]}"
    while (($# > 0)); do
        case "$1" in
            --ref) release_need_value "$@"; ref="$2"; shift ;;
            --previous) release_need_value "$@"; previous="$2"; have_previous=1; shift ;;
            *) die "release migrations: unknown option '$1' (see ./flow release --help)" ;;
        esac
        shift
    done
    commit="$(release_resolve_commit "$ref")" || die "release: cannot resolve --ref '$ref' to a commit."
    if [[ "$previous" != none ]]; then
        prev_commit="$(release_resolve_commit "$previous")" || die "release: cannot resolve --previous '$previous' to a commit."
    fi

    step "Migrations at $ref (${commit:0:7})"
    release_migration_diff "$commit" "${prev_commit:-none}"
    if ((have_previous == 0)); then
        info "  no --previous given: every migration is shown as new. Pass --previous <ref> for the comparison a build uses."
    elif [[ "$previous" == none ]]; then
        info "  previous: none (first release): every migration is new."
    else
        info "  compared with ${previous} (${prev_commit:0:7})"
    fi
    release_print_migrations
    info "The scanner is a red-flag generator and nothing more. It cannot prove a migration safe, and only a person sets schema_rollback."
}
