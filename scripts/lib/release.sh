#!/usr/bin/env bash
# Helpers for ./flow release (scripts/commands/release.sh). Sourced, never executed.
#
# Everything here is DEVELOPER-SIDE and a pure function of a commit (docs/adr/0027, "Production
# operations boundary"): it reads git, builds in throwaway containers, and writes an artifact. It
# never connects to the production host and holds no production credentials.
# shellcheck shell=bash

: "${FLOW_ROOT:?release.sh must be sourced by ./flow}"

# Keep in step with compose.yaml (scripts/tests/release.sh fails if they drift): the build runs in the
# project's own images so the extension set is what production requires, not what the builder has.
RELEASE_PHP_IMAGE="flowlife-dev/php:8.3"
RELEASE_NODE_IMAGE="node:24-bookworm-slim"

RELEASE_PLATFORM="apps/platform"
RELEASE_CONSOLE="apps/guardian-console"
RELEASE_MIGRATIONS_DIR="$RELEASE_PLATFORM/database/migrations"
RELEASE_ROLLBACK_VALUES=(not-applicable code-only restore-required)

# What is copied from the platform tree into the artifact. An ALLOWLIST: anything not named here (tests,
# openapi/, .env.example, tool configuration, and anything added tomorrow) cannot reach production by
# accident. scripts/release/artifact.php then judges the result independently.
RELEASE_ALLOWLIST=(artisan composer.json composer.lock app bootstrap config database public routes storage vendor)

# Cleanup registry. One EXIT trap removes the worktree and every temporary directory, whatever failed.
RELEASE_WORKTREE=""
RELEASE_TMPS=()
RELEASE_PARTIALS=() # half-published output files, removed if the build dies before they are renamed into place

release_cleanup() {
    local d
    trap - EXIT INT TERM
    if [[ -n "$RELEASE_WORKTREE" ]]; then
        git -C "$FLOW_ROOT" worktree remove --force "$RELEASE_WORKTREE" >/dev/null 2>&1 || true
        git -C "$FLOW_ROOT" worktree prune >/dev/null 2>&1 || true
    fi
    for d in "${RELEASE_TMPS[@]}"; do
        rm -rf -- "$d"
    done
    for d in "${RELEASE_PARTIALS[@]}"; do
        rm -f -- "$d"
    done
}

release_arm_cleanup() {
    trap release_cleanup EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
}

# release_mktemp: a private temporary directory, registered for cleanup. Sets REPLY (a command
# substitution would run in a subshell and lose the registration).
release_mktemp() {
    REPLY="$(mktemp -d "${TMPDIR:-/tmp}/flow-release.XXXXXX")"
    RELEASE_TMPS+=("$REPLY")
}

# --- JSON writing -----------------------------------------------------------
# Written by hand because the host has no jq. Every build re-parses the result with PHP's
# json_decode inside artifact.php, so an escaping bug here fails the build rather than shipping.

json_str() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    s="${s//$'\n'/\\n}"
    s="${s//$'\r'/\\r}"
    s="${s//$'\t'/\\t}"
    printf '"%s"' "$s"
}

json_list() {
    local first=1 item
    printf '['
    for item in "$@"; do
        ((first)) || printf ','
        first=0
        json_str "$item"
    done
    printf ']'
}

json_bool() { if [[ "$1" == 1 ]]; then printf 'true'; else printf 'false'; fi; }

# --- Git: refs, provenance, the previous release ---------------------------

# release_resolve_commit REF: print the commit sha REF names, or fail.
release_resolve_commit() {
    [[ -n "$1" && "$1" != -* ]] || return 1
    git rev-parse --verify --quiet --end-of-options "$1^{commit}" 2>/dev/null
}

# release_provenance REF COMMIT: sets SRC_TAG ("" when REF is not an annotated tag), SRC_ANNOTATED (0|1)
# and SRC_ON_MAIN (0|1). An annotated tag is a tag OBJECT: a lightweight tag is just a name and says
# nothing about who tagged what or why.
release_provenance() {
    local ref="$1" commit="$2" main_ref="" tag_commit=""
    SRC_TAG=""
    SRC_ANNOTATED=0
    if [[ "$(git cat-file -t "refs/tags/$ref" 2>/dev/null || true)" == tag ]]; then
        # The tag object must peel to EXACTLY the commit this build already resolved (COMMIT, resolved
        # by the caller before this function ever ran). Without this, a tag force-moved between that
        # resolution and this check — a real if narrow TOCTOU window, not a hypothetical one: refs are
        # read twice here, at two different times, because provenance is a separate git call from commit
        # resolution — would stamp "annotated tag, reachable from main" onto a commit the tag no longer
        # even names. A mismatch is treated as not annotated, never as the build guessing which one to
        # trust: fails closed into requiring --allow-untagged rather than silently trusting a stale ref.
        tag_commit="$(git rev-parse --verify --quiet "refs/tags/$ref^{commit}" 2>/dev/null || true)"
        if [[ "$tag_commit" == "$commit" ]]; then
            # A tag name becomes part of the artifact filename and is echoed to the operator's
            # terminal (release_build's "Release plan"); a control character or embedded newline in it
            # is a display-integrity risk there, not merely unusual. git itself allows names ordinary
            # tooling would not expect (e.g. containing '/'), so this is checked explicitly rather than
            # assumed safe because git accepted it.
            if [[ "$ref" == *[[:cntrl:]]* ]]; then
                die "release: the tag name '$ref' contains a control character; refusing to use it as provenance or an artifact name."
            fi
            SRC_TAG="$ref"
            SRC_ANNOTATED=1
        fi
    fi

    # The shared branch is the truth when there is one; a stale local main would refuse a tag that has
    # been merged, and a local main ahead of origin would approve one that was never pushed.
    if git rev-parse --verify --quiet refs/remotes/origin/main >/dev/null; then
        main_ref=refs/remotes/origin/main
    elif git rev-parse --verify --quiet refs/heads/main >/dev/null; then
        main_ref=refs/heads/main
    fi
    SRC_ON_MAIN=0
    if [[ -n "$main_ref" ]] && git merge-base --is-ancestor "$commit" "$main_ref" 2>/dev/null; then
        SRC_ON_MAIN=1
    fi
}

# --- Migrations: the diff, the scanner --------------------------------------
# ADR 0027: tooling may list migrations, inspect them and emit warnings. It may NOT set the rollback
# classification, and a clean scan proves nothing (a NOT NULL column with no default contains none of
# the keywords and breaks the previous release). Everything below produces facts for a human.

# release_migration_blobs COMMIT NAMEREF: fill the associative array NAMEREF with name -> blob id for
# the migrations at COMMIT.
release_migration_blobs() {
    local commit="$1" meta path type blob
    local -n _out="$2"
    _out=()
    while IFS=$'\t' read -r meta path; do
        [[ "$path" == "$RELEASE_MIGRATIONS_DIR/"*.php ]] || continue
        [[ "${path#"$RELEASE_MIGRATIONS_DIR/"}" != */* ]] || continue
        read -r _ type blob <<<"$meta"
        [[ "$type" == blob ]] || continue
        _out["${path##*/}"]="$blob"
    done < <(git ls-tree -r "$commit" -- "$RELEASE_MIGRATIONS_DIR/")
}

# release_scan_migration COMMIT NAME: print "<line><TAB><text>" for each statement in up() that mentions
# DROP, RENAME, MODIFY or CHANGE (as a Laravel method such as dropColumn/->change(), or as SQL). down()
# is not scanned: every create migration's down() drops its table, so scanning it would flag everything
# and mean nothing. Best effort by design: brace counting, not a PHP parser.
release_scan_migration() {
    git show "$1:$RELEASE_MIGRATIONS_DIR/$2" | awk '
        BEGIN { inup = 0; opened = 0; depth = 0; seen = 0 }
        {
            line = $0
            if (!inup && !seen && line ~ /function[ \t]+up[ \t]*\(/) { inup = 1; seen = 1 }
            if (!inup) next
            code = line
            sub(/\/\/.*$/, "", code)
            if (code ~ /^[ \t]*(\/\*|\*|#)/) code = ""
            if (code ~ /(^|[^A-Za-z])(drop|Drop|DROP|rename|Rename|RENAME|modify|Modify|MODIFY|change|Change|CHANGE)([^a-z]|$)/) {
                text = line
                gsub(/^[ \t]+|[ \t]+$/, "", text)
                gsub(/[\001-\010\013\014\016-\037]/, "", text)
                print NR "\t" substr(text, 1, 120)
            }
            o = gsub(/\{/, "{", line)
            c = gsub(/\}/, "}", line)
            if (o > 0) opened = 1
            depth += o - c
            if (opened && depth <= 0) inup = 0
        }
        END { if (!seen) print "0\t(no up() method found: this migration was not scanned)" }
    '
}

# release_migration_diff COMMIT PREVIOUS: PREVIOUS is a commit sha or the word "none". Sets
#   MIG_ALL MIG_NEW MIG_REMOVED MIG_MODIFIED   (arrays of migration file names, sorted)
#   MIG_FINDINGS                               (array of "name<TAB>kind<TAB>line<TAB>text")
release_migration_diff() {
    local commit="$1" previous="$2" name line text
    local -A now=() before=()
    MIG_ALL=() MIG_NEW=() MIG_REMOVED=() MIG_MODIFIED=() MIG_FINDINGS=()

    release_migration_blobs "$commit" now
    if [[ "$previous" != none ]]; then
        release_migration_blobs "$previous" before
    fi

    while IFS= read -r name; do
        [[ -n "$name" ]] || continue
        MIG_ALL+=("$name")
        if [[ -z "${before[$name]+set}" ]]; then
            MIG_NEW+=("$name")
        elif [[ "${before[$name]}" != "${now[$name]}" ]]; then
            MIG_MODIFIED+=("$name")
        fi
    done < <(printf '%s\n' "${!now[@]}" | LC_ALL=C sort)

    while IFS= read -r name; do
        [[ -n "$name" && -z "${now[$name]+set}" ]] && MIG_REMOVED+=("$name")
    done < <(printf '%s\n' "${!before[@]}" | LC_ALL=C sort)

    for name in "${MIG_NEW[@]}"; do
        while IFS=$'\t' read -r line text; do
            [[ -n "$line" ]] || continue
            if [[ "$line" == 0 ]]; then
                MIG_FINDINGS+=("$name"$'\t'"unparsed"$'\t'"0"$'\t'"$text")
            else
                MIG_FINDINGS+=("$name"$'\t'"keyword"$'\t'"$line"$'\t'"$text")
            fi
        done < <(release_scan_migration "$commit" "$name")
    done
    # An applied migration that changed or vanished means production's schema history no longer matches
    # the code's. Never proof of harm, always worth a person looking.
    for name in "${MIG_MODIFIED[@]}"; do
        MIG_FINDINGS+=("$name"$'\t'"modified"$'\t'"0"$'\t'"changed since the previous release; production already ran the old version")
    done
    for name in "${MIG_REMOVED[@]}"; do
        MIG_FINDINGS+=("$name"$'\t'"removed"$'\t'"0"$'\t'"present in the previous release, absent from this one")
    done
}

# release_print_migrations: human-readable rendering of the MIG_* arrays.
release_print_migrations() {
    local f name kind line text
    info "  ${#MIG_ALL[@]} migration(s) at this ref; ${#MIG_NEW[@]} new, ${#MIG_MODIFIED[@]} modified, ${#MIG_REMOVED[@]} removed"
    for name in "${MIG_NEW[@]}"; do info "    new       $name"; done
    for name in "${MIG_MODIFIED[@]}"; do info "    modified  $name"; done
    for name in "${MIG_REMOVED[@]}"; do info "    removed   $name"; done
    if ((${#MIG_FINDINGS[@]} == 0)); then
        info "  scanner: no findings. A clean scan proves nothing (ADR 0027): a NOT NULL column added without a default has none of these keywords and still breaks the previous release."
        return 0
    fi
    warn "scanner: ${#MIG_FINDINGS[@]} finding(s). A red flag, never a verdict."
    for f in "${MIG_FINDINGS[@]}"; do
        IFS=$'\t' read -r name kind line text <<<"$f"
        printf '    %s:%s [%s] %s\n' "$name" "$line" "$kind" "$text" >&2
    done
}

# --- Classification gate ----------------------------------------------------

release_valid_rollback() {
    local v
    for v in "${RELEASE_ROLLBACK_VALUES[@]}"; do [[ "$1" == "$v" ]] && return 0; done
    return 1
}

# release_check_classification VALUE PREVIOUS ACK: enforce the rules around the human's classification
# and set ACK_REQUIRED (0|1). It only ever REFUSES; it never chooses or changes VALUE.
release_check_classification() {
    local value="$1" previous="$2" ack="$3"
    release_valid_rollback "$value" ||
        die "release: --schema-rollback must be one of: ${RELEASE_ROLLBACK_VALUES[*]} (got '$value'). A person decides this (ADR 0027); the build never guesses."

    if [[ "$previous" == none ]]; then
        [[ "$value" == restore-required ]] ||
            die "release: with --previous none this is a first release: every migration is new and there is no earlier code to switch back to, so the only recovery is the pre-release database backup. The classification must be restore-required (got '$value')."
    elif ((${#MIG_NEW[@]} == 0)); then
        [[ "$value" == not-applicable ]] ||
            die "release: no migration is new relative to the previous release, so the classification is not-applicable (got '$value'). code-only and restore-required describe a schema change."
    else
        [[ "$value" != not-applicable ]] ||
            die "release: ${#MIG_NEW[@]} migration(s) are new relative to the previous release, so not-applicable is wrong. Choose code-only (the previous release runs correctly against the new schema) or restore-required (it cannot, or data is destroyed)."
    fi

    ACK_REQUIRED=0
    if ((${#MIG_FINDINGS[@]} > 0)) && [[ "$value" != restore-required ]]; then
        ACK_REQUIRED=1
        if [[ "$ack" != 1 ]]; then
            release_print_migrations
            die "release: the scanner reported findings and you chose '$value', which is less conservative than restore-required. Re-read the findings above; if you have judged them and still mean '$value', pass --acknowledge-scanner-findings (both the findings and your choice are recorded in release.json). Choosing restore-required needs no acknowledgement."
        fi
    fi
}

# --- Manifest ---------------------------------------------------------------

# release_write_manifest FILE: write release.json from the RM_* variables (set by the build).
release_write_manifest() {
    local out="$1" f name kind line text first=1
    {
        printf '{\n'
        printf '  "manifest_version": 1,\n'
        printf '  "release_id": %s,\n' "$(json_str "$RM_RELEASE_ID")"
        if [[ -n "$RM_VERSION" ]]; then printf '  "version": %s,\n' "$(json_str "$RM_VERSION")"; else printf '  "version": null,\n'; fi
        printf '  "built_at": %s,\n' "$(json_str "$RM_BUILT_AT")"
        printf '  "source": {\n'
        printf '    "ref": %s,\n' "$(json_str "$RM_REF")"
        printf '    "commit": %s,\n' "$(json_str "$RM_COMMIT")"
        if [[ -n "$SRC_TAG" ]]; then printf '    "tag": %s,\n' "$(json_str "$SRC_TAG")"; else printf '    "tag": null,\n'; fi
        printf '    "annotated_tag": %s,\n' "$(json_bool "$SRC_ANNOTATED")"
        printf '    "on_main": %s,\n' "$(json_bool "$SRC_ON_MAIN")"
        printf '    "provenance_override": %s\n' "$(json_bool "$RM_OVERRIDE")"
        printf '  },\n'
        printf '  "lockfiles": {\n'
        printf '    "composer.lock": %s,\n' "$(json_str "$RM_COMPOSER_LOCK_SHA")"
        printf '    "package-lock.json": %s\n' "$(json_str "$RM_PACKAGE_LOCK_SHA")"
        printf '  },\n'
        printf '  "migrations": {\n'
        if [[ "$RM_PREVIOUS_KIND" == none ]]; then
            printf '    "previous": null,\n'
        else
            printf '    "previous": {"ref": %s, "commit": %s},\n' "$(json_str "$RM_PREVIOUS_REF")" "$(json_str "$RM_PREVIOUS_COMMIT")"
        fi
        printf '    "all": %s,\n' "$(json_list "${MIG_ALL[@]}")"
        printf '    "new": %s,\n' "$(json_list "${MIG_NEW[@]}")"
        printf '    "removed": %s,\n' "$(json_list "${MIG_REMOVED[@]}")"
        printf '    "modified": %s,\n' "$(json_list "${MIG_MODIFIED[@]}")"
        printf '    "scanner_findings": ['
        for f in "${MIG_FINDINGS[@]}"; do
            IFS=$'\t' read -r name kind line text <<<"$f"
            ((first)) || printf ','
            first=0
            printf '\n      {"migration": %s, "kind": %s, "line": %s, "text": %s}' \
                "$(json_str "$name")" "$(json_str "$kind")" "$line" "$(json_str "$text")"
        done
        if ((first)); then printf ']\n'; else printf '\n    ]\n'; fi
        printf '  },\n'
        printf '  "schema_rollback": {\n'
        printf '    "value": %s,\n' "$(json_str "$RM_SCHEMA_ROLLBACK")"
        printf '    "classified_by": %s,\n' "$(json_str "$RM_CLASSIFIED_BY")"
        printf '    "acknowledgement": {"required": %s, "provided": %s}\n' "$(json_bool "$ACK_REQUIRED")" "$(json_bool "$RM_ACK_PROVIDED")"
        printf '  }\n'
        printf '}\n'
    } >"$out"
}

# --- Containers -------------------------------------------------------------

release_require_images() {
    docker image inspect "$RELEASE_PHP_IMAGE" >/dev/null 2>&1 ||
        die "the PHP image $RELEASE_PHP_IMAGE is not built. Run ./flow setup first (it builds it); the release build runs in the project's own image so its extensions match production."
}

# release_php DOCKER_ARGS... -- COMMAND...: the PHP image, as the invoking user. Files it writes are
# owned by the developer, so cleanup never needs privileges.
release_php() {
    local args=()
    while (($# > 0)) && [[ "$1" != -- ]]; do
        args+=("$1")
        shift
    done
    shift
    docker run --rm --user "$(id -u):$(id -g)" "${args[@]}" "$RELEASE_PHP_IMAGE" "$@"
}

release_node() {
    local args=()
    while (($# > 0)) && [[ "$1" != -- ]]; do
        args+=("$1")
        shift
    done
    shift
    docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -e npm_config_cache=/tmp/.npm \
        -e npm_config_update_notifier=false "${args[@]}" "$RELEASE_NODE_IMAGE" "$@"
}

# release_install_dependencies WORKTREE: production dependencies from the lockfiles, in the isolated
# checkout. --no-scripts: nothing in the artifact should depend on a build-time script having run
# (package discovery is regenerated by the host's config:cache).
release_install_dependencies() {
    local wt="$1"
    step "Validating composer.json against its lockfile"
    release_php -v "$wt/$RELEASE_PLATFORM:/var/www/platform" -w /var/www/platform -- \
        composer validate --strict --no-check-publish --no-interaction ||
        die "release: composer.json and composer.lock disagree at this commit; the build packages what the lockfile says."
    step "Installing production dependencies from composer.lock (no dev, optimized)"
    release_php -v "$wt/$RELEASE_PLATFORM:/var/www/platform" -w /var/www/platform -- \
        composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader ||
        die "release: composer install failed."
}

release_build_console() {
    local wt="$1"
    step "Installing Guardian Console dependencies from package-lock.json"
    release_node -v "$wt/$RELEASE_CONSOLE:/app" -w /app -- npm ci --no-audit --no-fund ||
        die "release: npm ci failed."
    step "Building the Guardian Console (production)"
    release_node -v "$wt/$RELEASE_CONSOLE:/app" -w /app -- npm run build ||
        die "release: the Console build failed."
    release_node -v "$wt/$RELEASE_CONSOLE:/app" -w /app -- npm run verify:build ||
        die "release: the Console build did not verify."
    [[ -f "$wt/$RELEASE_CONSOLE/dist/index.html" ]] ||
        die "release: the Console build produced no dist/index.html."
}

# release_stage WORKTREE STAGE: assemble the artifact tree. Copies only the allowlist from the platform
# tree, then merges the Console build into public/ (refusing to overwrite anything).
release_stage() {
    local wt="$1" stage="$2" p rel
    local src="$wt/$RELEASE_PLATFORM" dist="$wt/$RELEASE_CONSOLE/dist"
    mkdir -p "$stage"
    for p in "${RELEASE_ALLOWLIST[@]}"; do
        [[ -e "$src/$p" ]] || die "release: '$p' is missing from the platform tree at this commit."
        cp -a "$src/$p" "$stage/$p"
    done

    while IFS= read -r -d '' rel; do
        rel="${rel#./}"
        if [[ -e "$stage/public/$rel" || -L "$stage/public/$rel" ]]; then
            die "release: the Console build contains public/$rel, which already exists in the platform's public directory. Refusing to overwrite it."
        fi
        mkdir -p "$stage/public/$(dirname "$rel")"
        cp -a "$dist/$rel" "$stage/public/$rel"
    done < <(cd "$dist" && find . \( -type f -o -type l \) -print0)
}

# release_cache_smoke STAGE: prove the three approved optimization commands still succeed (ADR 0027,
# "Optimization model") on a throwaway copy, then discard it. The caches are never shipped: they bake in
# the host's absolute paths and belong to the release directory they are built in.
release_cache_smoke() {
    local stage="$1" copy c key
    release_mktemp
    copy="$REPLY"
    cp -a "$stage/." "$copy/"
    # A fixed, meaningless key: it exists so the application boots, and protects nothing.
    key="base64:$(printf '%032d' 0 | base64)"

    step "Checking the approved cache commands succeed (config:cache, event:cache, route:cache)"
    for c in config:cache event:cache route:cache; do
        release_php --network none -e APP_ENV=production -e APP_DEBUG=false -e "APP_KEY=$key" \
            -e APP_URL=https://release-smoke.invalid -v "$copy:/var/www/platform" -w /var/www/platform -- \
            php artisan "$c" >/dev/null ||
            die "release: 'php artisan $c' failed against this build. The deployment runbook runs it on the host; it must succeed here first."
    done
    if [[ ! -f "$copy/bootstrap/cache/config.php" || ! -f "$copy/bootstrap/cache/events.php" ]] ||
        ! compgen -G "$copy/bootstrap/cache/routes-*.php" >/dev/null; then
        die "release: the cache commands exited 0 but did not write config, event and route caches."
    fi
}

# release_package STAGE FILE EPOCH: a deterministic gzip'd tar of STAGE's contents.
release_package() {
    local stage="$1" file="$2" epoch="$3"
    tar --sort=name --mtime="@$epoch" --owner=0 --group=0 --numeric-owner \
        -cf - -C "$stage" . | gzip -n -9 >"$file"
}

# --- Verification (shared by `inspect` and by `build`'s check of its own output) ------------------

# release_verify_artifact TARBALL: checksum, safe extraction, then the artifact.php judgement.
# Returns non-zero (after saying why) for anything that is not a valid artifact.
release_verify_artifact() {
    local tarball="$1" sumfile="$1.sha256" base line entries kind tree target

    [[ -f "$tarball" ]] || {
        bad "no such artifact: $tarball"
        return 1
    }
    [[ -f "$sumfile" ]] || {
        bad "no checksum file $sumfile: an artifact is not inspected, let alone deployed, without its checksum."
        return 1
    }
    base="$(basename "$tarball")"
    line="$(cat "$sumfile")"
    if [[ "$line" != "${line:0:64}  $base" || ! "${line:0:64}" =~ ^[0-9a-f]{64}$ ]]; then
        bad "$sumfile is not a single '<sha256>  $base' line."
        return 1
    fi
    if ! (cd "$(dirname "$tarball")" && sha256sum --check --status "$base.sha256"); then
        bad "CHECKSUM MISMATCH: $base does not match $base.sha256. Do not extract it."
        return 1
    fi
    ok "checksum matches (sha256 ${line:0:64})"

    # Before extracting anything: only regular files, directories and symlinks; no absolute or
    # parent-relative names. GNU tar refuses `..` members on extraction as well; this says so first.
    if ! entries="$(tar -tzf "$tarball" 2>/dev/null)"; then
        bad "$base is not a readable gzip'd tar archive."
        return 1
    fi
    if grep -Eq '(^/|(^|/)\.\.(/|$))' <<<"$entries"; then
        bad "the archive contains absolute or parent-relative paths."
        return 1
    fi
    # Absolute symlink TARGETS are refused here explicitly, before extraction, rather than relying
    # solely on GNU tar's own extraction-order protection against writing through one (verified
    # separately, by hand, against crafted archives — but that protection is tar's implementation, not
    # a contract this script controls, and this check does not need it to hold). A RELATIVE target
    # (`../pkg/bin/tool`, exactly what Composer's own bin symlinks look like) is not checked here: it is
    # only unsafe if it climbs higher than the symlink's own depth in the tree, which depends on where
    # the symlink sits — that arithmetic is what artifact.php's checkSymlink() does correctly, with a
    # real resolved path, after extraction. An absolute target needs no such arithmetic: it is unsafe
    # unconditionally, so it is refused at the earliest possible point instead.
    while IFS= read -r kind; do
        case "${kind:0:1}" in
            - | d) ;;
            l)
                target="${kind##* -> }"
                if [[ "$target" == /* ]]; then
                    bad "the archive contains a symlink with an absolute target (target: $target)."
                    return 1
                fi
                ;;
            *)
                bad "the archive contains an entry that is not a file, directory or symlink (${kind:0:1})."
                return 1
                ;;
        esac
    done < <(tar -tvzf "$tarball" 2>/dev/null)

    release_mktemp
    tree="$REPLY"
    if ! tar -xzf "$tarball" -C "$tree" --no-same-owner --same-permissions 2>/dev/null; then
        bad "extraction failed (the archive is damaged or unsafe)."
        return 1
    fi

    release_php --network none -v "$FLOW_ROOT/scripts/release:/release:ro" -v "$tree:/artifact:ro" -- \
        php /release/artifact.php inspect /artifact
}
